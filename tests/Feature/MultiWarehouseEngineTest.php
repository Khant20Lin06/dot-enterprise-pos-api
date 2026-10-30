<?php

use App\Domains\Inventory\Actions\CancelStockTransferAction;
use App\Domains\Inventory\Actions\CreateStockTransferAction;
use App\Domains\Inventory\Actions\CreateWarehouseAction;
use App\Domains\Inventory\Actions\DeductStockAction;
use App\Domains\Inventory\Actions\DispatchStockTransferAction;
use App\Domains\Inventory\Actions\ReceiveStockTransferAction;
use App\Domains\Inventory\Actions\ReplenishStockAction;
use App\Domains\Inventory\Events\StockTransferDispatchedEvent;
use App\Domains\Inventory\Events\StockTransferReceivedEvent;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Exceptions\InsufficientStockException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Event::fake([
        StockTransferDispatchedEvent::class,
        StockTransferReceivedEvent::class,
    ]);
});

it('creates and updates warehouse locations', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $code = 'WH_CENTRAL_' . rand(100, 999);
    $response = $this->postJson('/api/v1/warehouses', [
        'code' => $code,
        'name' => 'Mingaladon Central Hub',
        'type' => 'Central Warehouse',
        'address' => 'No. 45, Airport Road, Mingaladon, Yangon',
        'phone' => '0911223344',
        'is_central' => true,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'code' => $code,
                'name' => 'Mingaladon Central Hub',
                'isCentral' => true,
            ],
        ]);

    $warehouseId = $response->json('data.id');

    $updateResp = $this->putJson("/api/v1/warehouses/{$warehouseId}", [
        'name' => 'Mingaladon Logistics Distribution Center',
        'phone' => '0999887766',
    ]);

    $updateResp->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Mingaladon Logistics Distribution Center',
                'phone' => '0999887766',
            ],
        ]);
});

it('maintains independent stock quantities per physical warehouse location', function () {
    $product = Product::where('is_labor', false)->first();

    $central = Warehouse::create([
        'code' => 'WH_MAIN_' . rand(100, 999),
        'name' => 'Central Warehouse',
        'is_central' => true,
    ]);

    $branch = Warehouse::create([
        'code' => 'BR_HLEDAN_' . rand(100, 999),
        'name' => 'Hledan Branch Counter',
        'is_central' => false,
    ]);

    $replenishAction = app(ReplenishStockAction::class);
    $deductAction = app(DeductStockAction::class);

    // Replenish 50 units into Central and 10 units into Branch
    $replenishAction->execute($product->id, 50, null, $central->id);
    $replenishAction->execute($product->id, 10, null, $branch->id);

    $centralStock = WarehouseStock::where('warehouse_id', $central->id)->where('product_id', $product->id)->first();
    $branchStock = WarehouseStock::where('warehouse_id', $branch->id)->where('product_id', $product->id)->first();

    expect($centralStock->stock_qty)->toBe(50)
        ->and($branchStock->stock_qty)->toBe(10);

    // Deducting 5 units from Branch succeeds
    $deductAction->execute($product->id, 5, null, $branch->id);
    expect($branchStock->fresh()->stock_qty)->toBe(5);

    // Attempting to deduct 20 units from Branch must fail, even though Central has 50 units!
    expect(fn () => $deductAction->execute($product->id, 20, null, $branch->id))
        ->toThrow(InsufficientStockException::class, 'has insufficient stock');
});

it('drafts, dispatches and receives a stock transfer order (Two-step flow)', function () {
    $staff = StaffUser::first();
    $product = Product::where('is_labor', false)->first();

    $source = Warehouse::create(['code' => 'SRC_' . rand(100, 999), 'name' => 'Central Hub']);
    $dest = Warehouse::create(['code' => 'DST_' . rand(100, 999), 'name' => 'Sanchaung Workshop']);

    // Seed 30 units in Source Warehouse
    $replenishAction = app(ReplenishStockAction::class);
    $replenishAction->execute($product->id, 30, null, $source->id);

    $createAction = app(CreateStockTransferAction::class);
    $dispatchAction = app(DispatchStockTransferAction::class);
    $receiveAction = app(ReceiveStockTransferAction::class);

    // 1. Create Draft Transfer (15 units)
    $transfer = $createAction->execute([
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $dest->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 15,
            ],
        ],
    ], $staff->id);

    expect($transfer->status)->toBe('Draft')
        ->and($transfer->transfer_no)->toStartWith('TRF-');

    // Source warehouse stock is not decremented while draft
    $srcStock = WarehouseStock::where('warehouse_id', $source->id)->where('product_id', $product->id)->first();
    expect($srcStock->stock_qty)->toBe(30);

    // 2. Dispatch Transfer
    $dispatched = $dispatchAction->execute($transfer, $staff->id);

    expect($dispatched->status)->toBe('In Transit')
        ->and($srcStock->fresh()->stock_qty)->toBe(15); // Decremented from source!

    // Destination has not received stock yet
    $destStock = WarehouseStock::where('warehouse_id', $dest->id)->where('product_id', $product->id)->first();
    expect($destStock?->stock_qty ?? 0)->toBe(0);

    Event::assertDispatched(StockTransferDispatchedEvent::class);

    // 3. Receive Transfer
    $received = $receiveAction->execute($dispatched, $staff->id);

    $destStockAfter = WarehouseStock::where('warehouse_id', $dest->id)->where('product_id', $product->id)->first();

    expect($received->status)->toBe('Received')
        ->and($destStockAfter)->not->toBeNull()
        ->and($destStockAfter->stock_qty)->toBe(15); // Restocked in destination!

    Event::assertDispatched(StockTransferReceivedEvent::class);
});

it('blocks transfer when source warehouse has insufficient stock', function () {
    $staff = StaffUser::first();
    $product = Product::where('is_labor', false)->first();

    $source = Warehouse::create(['code' => 'SRC_LOW_' . rand(100, 999), 'name' => 'Small Hub']);
    $dest = Warehouse::create(['code' => 'DST_LOW_' . rand(100, 999), 'name' => 'Workshop']);

    // Seed only 3 units
    $replenishAction = app(ReplenishStockAction::class);
    $replenishAction->execute($product->id, 3, null, $source->id);

    $createAction = app(CreateStockTransferAction::class);
    $dispatchAction = app(DispatchStockTransferAction::class);

    // Attempt to transfer 10 units
    $transfer = $createAction->execute([
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $dest->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 10],
        ],
    ], $staff->id);

    expect(fn () => $dispatchAction->execute($transfer, $staff->id))
        ->toThrow(InsufficientStockException::class, 'has insufficient stock');
});

it('blocks creating a stock transfer where source and destination are the same', function () {
    $staff = StaffUser::first();
    $product = Product::where('is_labor', false)->first();
    $warehouse = Warehouse::create(['code' => 'SAME_' . rand(100, 999), 'name' => 'Same Warehouse']);

    $createAction = app(CreateStockTransferAction::class);

    expect(fn () => $createAction->execute([
        'source_warehouse_id' => $warehouse->id,
        'destination_warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $product->id, 'qty' => 5]],
    ], $staff->id))->toThrow(DomainException::class, 'cannot be the same physical location');
});

it('cancels an in-transit transfer and reverts dispatched stock back to source', function () {
    $staff = StaffUser::first();
    $product = Product::where('is_labor', false)->first();

    $source = Warehouse::create(['code' => 'SRC_REV_' . rand(100, 999), 'name' => 'Revert Hub']);
    $dest = Warehouse::create(['code' => 'DST_REV_' . rand(100, 999), 'name' => 'Branch']);

    $replenishAction = app(ReplenishStockAction::class);
    $replenishAction->execute($product->id, 20, null, $source->id);

    $createAction = app(CreateStockTransferAction::class);
    $dispatchAction = app(DispatchStockTransferAction::class);
    $cancelAction = app(CancelStockTransferAction::class);

    $transfer = $createAction->execute([
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $dest->id,
        'items' => [['product_id' => $product->id, 'qty' => 8]],
    ], $staff->id);

    $dispatched = $dispatchAction->execute($transfer, $staff->id);
    $srcStock = WarehouseStock::where('warehouse_id', $source->id)->where('product_id', $product->id)->first();
    expect($srcStock->stock_qty)->toBe(12); // 20 - 8

    // Cancel in-transit transfer
    $cancelled = $cancelAction->execute($dispatched, $staff->id);

    expect($cancelled->status)->toBe('Cancelled')
        ->and($srcStock->fresh()->stock_qty)->toBe(20); // Reverted back to 20!
});

it('queries warehouse stocks and low stock alerts via REST API', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    $warehouse = Warehouse::create(['code' => 'API_WH_' . rand(100, 999), 'name' => 'API Hub']);
    $product = Product::where('is_labor', false)->first();

    WarehouseStock::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'stock_qty' => 2,
        'min_alert_qty' => 5,
        'bin_location' => 'Rack A-01',
    ]);

    $response = $this->getJson("/api/v1/warehouses/{$warehouse->id}/stocks?low_stock=1");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'warehouseId', 'product', 'stockQty', 'minAlertQty', 'binLocation', 'isLowStock'],
            ],
        ]);

    expect($response->json('data.0.isLowStock'))->toBeTrue()
        ->and($response->json('data.0.binLocation'))->toBe('Rack A-01');
});
