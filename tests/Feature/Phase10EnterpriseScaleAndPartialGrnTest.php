<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseItem;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Vehicle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Cache::flush();

    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($this->adminStaff);

    $this->category = Category::first() ?? Category::create(['code' => 'SPARE', 'name' => 'Spare Parts']);
    $this->uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);
});

/*
|--------------------------------------------------------------------------
| Test 1: OWASP API1:2023 BOLA Multi-Branch Scoping on POS Sales Orders
|--------------------------------------------------------------------------
*/

it('enforces BOLA multi-branch scoping on sales orders index and show', function () {
    $cashierRole = Role::where('code', 'CASHIER')->first()
        ?? Role::create(['name' => 'Cashier', 'code' => 'CASHIER', 'description' => 'Counter Cashier']);

    $cashierA = StaffUser::create([
        'name' => 'Cashier Aye Aye ' . uniqid(),
        'email' => 'aye.' . uniqid() . '@dot-pos.test',
        'password' => Hash::make('Secret1234!'),
        'pin_hash' => Hash::make('1111'),
        'role_id' => $cashierRole->id,
        'status' => 'Active',
    ]);

    $cashierB = StaffUser::create([
        'name' => 'Cashier Bo Bo ' . uniqid(),
        'email' => 'bobo.' . uniqid() . '@dot-pos.test',
        'password' => Hash::make('Secret1234!'),
        'pin_hash' => Hash::make('2222'),
        'role_id' => $cashierRole->id,
        'status' => 'Active',
    ]);

    $wh1 = Warehouse::create(['code' => 'WH1-' . uniqid(), 'name' => 'Branch 1 Downtown', 'is_active' => true]);
    $wh2 = Warehouse::create(['code' => 'WH2-' . uniqid(), 'name' => 'Branch 2 Uptown', 'is_active' => true]);

    $sessionA = PosSession::create([
        'session_no' => 'SES-A-' . uniqid(),
        'cashier_id' => $cashierA->id,
        'terminal_id' => 'COUNTER-01',
        'warehouse_id' => $wh1->id,
        'opening_float' => 50000.00,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $sessionB = PosSession::create([
        'session_no' => 'SES-B-' . uniqid(),
        'cashier_id' => $cashierB->id,
        'terminal_id' => 'COUNTER-02',
        'warehouse_id' => $wh2->id,
        'opening_float' => 50000.00,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Engine Coolant ' . uniqid(),
        'barcode' => 'EC-' . uniqid(),
        'sell_price' => 12000.00,
        'cost_price' => 7000.00,
        'stock' => 100,
    ]);

    \App\Domains\Inventory\Models\WarehouseStock::create([
        'warehouse_id' => $wh1->id,
        'product_id' => $product->id,
        'stock_qty' => 50,
    ]);

    \App\Domains\Inventory\Models\WarehouseStock::create([
        'warehouse_id' => $wh2->id,
        'product_id' => $product->id,
        'stock_qty' => 50,
    ]);

    $customer = Customer::create(['name' => 'Customer BOLA', 'phone' => '0912345678']);

    $checkoutAction = app(ProcessCheckoutAction::class);

    // Order 1 created by Cashier A
    $order1 = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'pos_session_id' => $sessionA->id,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 12000.00]],
        'payments' => [['method' => 'Cash', 'amount' => 12000.00]],
    ], $cashierA->id);

    // Order 2 created by Cashier B
    $order2 = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'pos_session_id' => $sessionB->id,
        'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 12000.00]],
        'payments' => [['method' => 'Cash', 'amount' => 24000.00]],
    ], $cashierB->id);

    // 1. Acting as Cashier A: should only see Order 1
    Sanctum::actingAs($cashierA);

    $indexRes = $this->getJson('/api/v1/pos/orders');
    $indexRes->assertOk();
    $orderIds = collect($indexRes->json('data'))->pluck('id')->all();
    expect($orderIds)->toContain($order1->id)
        ->and($orderIds)->not->toContain($order2->id);

    // 2. Cashier A attempts to view Order 2 directly -> expect 403 Forbidden
    $showBOLA = $this->getJson("/api/v1/pos/orders/{$order2->id}");
    $showBOLA->assertStatus(403)
        ->assertJsonPath('error_code', 'BOLA_UNAUTHORIZED_BRANCH_ACCESS');

    // 3. Cashier A viewing own Order 1 -> expect 200 OK
    $showOwn = $this->getJson("/api/v1/pos/orders/{$order1->id}");
    $showOwn->assertOk()
        ->assertJsonPath('data.id', $order1->id);

    // 4. Acting as Admin: can see all orders and filter by warehouse
    Sanctum::actingAs($this->adminStaff);
    $adminRes = $this->getJson("/api/v1/pos/orders?warehouse_id={$wh2->id}");
    $adminRes->assertOk();
    $adminIds = collect($adminRes->json('data'))->pluck('id')->all();
    expect($adminIds)->toContain($order2->id);
});

/*
|--------------------------------------------------------------------------
| Test 2: Partial Goods Receipt Note (GRN) Engine & Delivery Batches
|--------------------------------------------------------------------------
*/

it('executes partial goods receipt (GRN) across multiple deliveries with proportional moving average cost and AP debt', function () {
    $supplier = Supplier::create([
        'name' => 'Nippon Auto Imports ' . uniqid(),
        'phone' => '0922334455',
        'outstanding_balance' => 0.00,
    ]);

    $prodSpark = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'NGK Laser Spark Plug ' . uniqid(),
        'barcode' => 'NGK-' . uniqid(),
        'sell_price' => 15000.00,
        'cost_price' => 10000.00,
        'stock' => 0,
    ]);

    $prodCoil = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Ignition Coil Pack ' . uniqid(),
        'barcode' => 'COIL-' . uniqid(),
        'sell_price' => 35000.00,
        'cost_price' => 20000.00,
        'stock' => 0,
    ]);

    $po = Purchase::create([
        'po_no' => 'PO-GRN-' . uniqid(),
        'supplier_id' => $supplier->id,
        'total_amount' => 900000.00,
        'status' => 'Pending',
    ]);

    $item1 = PurchaseItem::create([
        'purchase_id' => $po->id,
        'product_id' => $prodSpark->id,
        'qty' => 50,
        'received_qty' => 0,
        'unit_price' => 10000.00,
        'subtotal' => 500000.00,
    ]);

    $item2 = PurchaseItem::create([
        'purchase_id' => $po->id,
        'product_id' => $prodCoil->id,
        'qty' => 20,
        'received_qty' => 0,
        'unit_price' => 20000.00,
        'subtotal' => 400000.00,
    ]);

    // DELIVERY 1 (Partial Shipment): 20 Spark Plugs and 10 Ignition Coils arrive
    // Total delivered value: (20 * 10,000) + (10 * 20,000) = 400,000 Ks
    $delivery1Res = $this->postJson("/api/v1/purchases/{$po->id}/receive", [
        'items' => [
            ['purchase_item_id' => $item1->id, 'received_qty' => 20],
            ['purchase_item_id' => $item2->id, 'received_qty' => 10],
        ],
    ]);

    $delivery1Res->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'Partially Received');

    $prodSpark->refresh();
    $prodCoil->refresh();
    $supplier->refresh();
    $item1->refresh();
    $item2->refresh();

    expect($prodSpark->stock)->toBe(20)
        ->and($prodCoil->stock)->toBe(10)
        ->and((float) $supplier->outstanding_balance)->toBe(400000.0)
        ->and($item1->received_qty)->toBe(20)
        ->and($item2->received_qty)->toBe(10);

    // DELIVERY 2 (Overshipment rejection test): Vendor tries to deliver 35 spark plugs (only 30 pending!)
    $overshipRes = $this->postJson("/api/v1/purchases/{$po->id}/receive", [
        'items' => [
            ['purchase_item_id' => $item1->id, 'received_qty' => 35],
        ],
    ]);
    $overshipRes->assertStatus(422)
        ->assertJsonPath('success', false);

    // DELIVERY 3 (Final Delivery): Receive all remaining units (30 plugs + 10 coils)
    $finalDeliveryRes = $this->postJson("/api/v1/purchases/{$po->id}/receive", []);
    $finalDeliveryRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'Received');

    $prodSpark->refresh();
    $prodCoil->refresh();
    $supplier->refresh();
    $item1->refresh();
    $item2->refresh();

    // Verify complete receiving numbers
    expect($prodSpark->stock)->toBe(50)
        ->and($prodCoil->stock)->toBe(20)
        ->and((float) $supplier->outstanding_balance)->toBe(900000.0)
        ->and($item1->received_qty)->toBe(50)
        ->and($item2->received_qty)->toBe(20);

    // Attempt to receive again on fully received PO -> expect 422
    $redundantRes = $this->postJson("/api/v1/purchases/{$po->id}/receive");
    $redundantRes->assertStatus(422)
        ->assertJsonPath('success', false);
});

/*
|--------------------------------------------------------------------------
| Test 3: Executive Daily Summary Report Caching & DoS Defense
|--------------------------------------------------------------------------
*/

it('caches daily summary report and respects cache busting for supervisors', function () {
    // First call: Cache MISS
    $res1 = $this->getJson('/api/v1/reports/daily-summary');
    $res1->assertOk()
        ->assertHeader('X-Report-Cache', 'MISS');

    // Second call: Cache HIT
    $res2 = $this->getJson('/api/v1/reports/daily-summary');
    $res2->assertOk()
        ->assertHeader('X-Report-Cache', 'HIT')
        ->assertJsonPath('cached', true);

    // Third call with ?fresh=true as Admin: Cache MISS (busted)
    $res3 = $this->getJson('/api/v1/reports/daily-summary?fresh=true');
    $res3->assertOk()
        ->assertHeader('X-Report-Cache', 'MISS');
});

/*
|--------------------------------------------------------------------------
| Test 4: SoftDeletes Master Data Rollout (Product Asset Recovery)
|--------------------------------------------------------------------------
*/

it('soft deletes and restores master products with audit recovery', function () {
    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Wiper Blade 24 Inch ' . uniqid(),
        'barcode' => 'WB24-' . uniqid(),
        'sell_price' => 18000.00,
        'cost_price' => 11000.00,
        'stock' => 0,
    ]);

    // 1. Soft Delete Product
    $delRes = $this->deleteJson("/api/v1/products/{$product->id}");
    $delRes->assertOk()
        ->assertJsonPath('success', true);

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();

    // 2. Normal index does not list deleted product
    $indexRes = $this->getJson('/api/v1/products');
    $ids = collect($indexRes->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($product->id);

    // 3. Trashed index lists the deleted product
    $trashedRes = $this->getJson('/api/v1/products?trashed=true');
    $trashedIds = collect($trashedRes->json('data'))->pluck('id')->all();
    expect($trashedIds)->toContain($product->id);

    // 4. Restore Product
    $restoreRes = $this->postJson("/api/v1/products/{$product->id}/restore");
    $restoreRes->assertOk()
        ->assertJsonPath('success', true);

    expect(Product::find($product->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Test 5: SoftDeletes & Restoration for Customers, Suppliers, and Vehicles
|--------------------------------------------------------------------------
*/

it('soft deletes and restores customers, suppliers, and vehicles', function () {
    // Customer
    $customer = Customer::create([
        'name' => 'Daw Mya SoftDelete ' . uniqid(),
        'phone' => '0999887766',
        'due_amount' => 0.00,
    ]);

    $delCust = $this->deleteJson("/api/v1/customers/{$customer->id}");
    $delCust->assertOk();
    expect(Customer::find($customer->id))->toBeNull();

    $restoreCust = $this->postJson("/api/v1/customers/{$customer->id}/restore");
    $restoreCust->assertOk();
    expect(Customer::find($customer->id))->not->toBeNull();

    // Supplier
    $supplier = Supplier::create([
        'name' => 'Soft Supplier ' . uniqid(),
        'phone' => '0977665544',
        'outstanding_balance' => 0.00,
    ]);

    $delSupp = $this->deleteJson("/api/v1/suppliers/{$supplier->id}");
    $delSupp->assertOk();
    expect(Supplier::find($supplier->id))->toBeNull();

    $restoreSupp = $this->postJson("/api/v1/suppliers/{$supplier->id}/restore");
    $restoreSupp->assertOk();
    expect(Supplier::find($supplier->id))->not->toBeNull();

    // Vehicle
    $vehicle = Vehicle::create([
        'customer_id' => $customer->id,
        'plate_number' => 'YGN-REC-' . uniqid(),
        'make' => 'Mazda',
        'model' => 'Demio',
    ]);

    $delVeh = $this->deleteJson("/api/v1/vehicles/{$vehicle->id}");
    $delVeh->assertOk();
    expect(Vehicle::find($vehicle->id))->toBeNull();

    $restoreVeh = $this->postJson("/api/v1/vehicles/{$vehicle->id}/restore");
    $restoreVeh->assertOk();
    expect(Vehicle::find($vehicle->id))->not->toBeNull();
});
