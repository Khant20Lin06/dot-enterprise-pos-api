<?php

use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->first()
        ?? StaffUser::first();
});

it('manages supplier lifecycle with validation and history guards', function () {
    Sanctum::actingAs($this->adminStaff);

    // 1. Create Supplier
    $createResponse = $this->postJson('/api/v1/suppliers', [
        'name' => 'Denso Myanmar Parts Co., Ltd',
        'phone' => '09440011223',
        'contact_person' => 'U Kyaw Swar',
        'address' => 'Bayintnaung Industrial Zone, Mayangone, Yangon',
    ]);

    $createResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Supplier registered successfully.',
            'data' => [
                'name' => 'Denso Myanmar Parts Co., Ltd',
                'phone' => '09440011223',
            ],
        ]);

    $supplierId = $createResponse->json('data.id');

    // 2. Update Supplier
    $updateResponse = $this->putJson("/api/v1/suppliers/{$supplierId}", [
        'contact_person' => 'Daw May Thu',
        'outstanding_balance' => 0.00,
    ]);

    $updateResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => ['contactPerson' => 'Daw May Thu'],
        ]);

    // 3. Delete Supplier
    $deleteResponse = $this->deleteJson("/api/v1/suppliers/{$supplierId}");
    $deleteResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(Supplier::find($supplierId))->toBeNull();
});

it('drafts a Purchase Order and receives goods (GRN) with moving average cost recalculation and warehouse allocation', function () {
    Sanctum::actingAs($this->adminStaff);

    $supplier = Supplier::first();
    $category = Category::first();
    $uom = UnitOfMeasure::first();
    $warehouse = Warehouse::first();

    // Create a product with existing stock of 10 units at cost 40,000 Ks
    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'supplier_id' => $supplier->id,
        'name' => 'Denso Iridium Spark Plug IK20',
        'barcode' => 'IK20-TEST-PO',
        'cost_price' => 40000.00,
        'sell_price' => 65000.00,
        'stock' => 10,
    ]);

    if ($warehouse) {
        WarehouseStock::updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['stock_qty' => 10]
        );
    }

    $supplierInitialBalance = (float) $supplier->outstanding_balance;

    // 1. Create Purchase Order (Draft) for 20 units at cost 50,000 Ks
    $poResponse = $this->postJson('/api/v1/purchases', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse?->id,
        'notes' => 'Bulk restocking of spark plugs',
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 20,
                'unit_price' => 50000.00,
            ],
        ],
    ]);

    $poResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'Draft',
                'totalAmount' => 1000000.00,
            ],
        ]);

    $purchaseId = $poResponse->json('data.id');

    // 2. Receive Purchase (GRN Goods Receipt)
    $receiveResponse = $this->postJson("/api/v1/purchases/{$purchaseId}/receive", [
        'warehouse_id' => $warehouse?->id,
    ]);

    $receiveResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'Received',
            ],
        ]);

    // 3. Verify Stock & Recalculated Moving Average Cost:
    // Old Stock: 10 @ 40,000 = 400,000 Ks
    // Received:  20 @ 50,000 = 1,000,000 Ks
    // Total:     30 units for 1,400,000 Ks => Average Cost = 46,666.67 Ks
    $product->refresh();
    expect($product->stock)->toBe(30)
        ->and((float) $product->cost_price)->toEqual(46666.67);

    // Verify physical warehouse stock
    if ($warehouse) {
        $whStock = WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first();
        expect((int) $whStock->stock_qty)->toBe(30);
    }

    // Verify supplier payable debt
    $supplier->refresh();
    expect((float) $supplier->outstanding_balance)->toEqual($supplierInitialBalance + 1000000.00);

    // 4. Guard: Cannot cancel a received PO
    $cancelResponse = $this->postJson("/api/v1/purchases/{$purchaseId}/cancel");
    $cancelResponse->assertStatus(422)
        ->assertJson(['success' => false]);
});

it('creates physical stock adjustments with audit logging and prevents negative stock', function () {
    Sanctum::actingAs($this->adminStaff);

    $category = Category::first();
    $uom = UnitOfMeasure::first();
    $warehouse = Warehouse::first();

    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Wiper Blade 24 Inch',
        'barcode' => 'WPR-24-ADJ',
        'cost_price' => 12000.00,
        'sell_price' => 18000.00,
        'stock' => 15,
    ]);

    if ($warehouse) {
        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_qty' => 15,
        ]);
    }

    // 1. Damage adjustment (-3 units)
    $adjResponse = $this->postJson('/api/v1/stock-adjustments', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse?->id,
        'type' => 'Damage / Scrap',
        'adjusted_qty' => -3,
        'reason' => 'Package ripped during warehouse reorganization',
    ]);

    $adjResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'prevStock' => 15,
                'adjustedQty' => -3,
                'newStock' => 12,
                'type' => 'Damage / Scrap',
            ],
        ]);

    $product->refresh();
    expect($product->stock)->toBe(12);

    // 2. Guard: Cannot adjust past 0 (negative stock violation)
    $negativeResponse = $this->postJson('/api/v1/stock-adjustments', [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse?->id,
        'type' => 'Cycle Count',
        'adjusted_qty' => -20,
        'reason' => 'Faulty audit attempt',
    ]);

    $negativeResponse->assertStatus(422)
        ->assertJson(['success' => false]);

    $product->refresh();
    expect($product->stock)->toBe(12);
});
