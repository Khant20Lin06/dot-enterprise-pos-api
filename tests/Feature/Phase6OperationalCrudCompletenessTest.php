<?php

use App\Domains\CreditAndLedger\Models\CreditSettlement;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVariant;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseItem;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($this->adminStaff);

    $this->category = Category::first() ?? Category::create(['code' => 'SPARE', 'name' => 'Spare Parts']);
    $this->uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);

    $this->product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Test Brake Disc',
        'barcode' => 'BD-' . uniqid(),
        'sell_price' => 45000.00,
        'cost_price' => 30000.00,
        'stock' => 20,
        'is_labor' => false,
        'has_variants' => false,
    ]);

    $this->supplier = Supplier::create([
        'name' => 'Apex Auto Spares ' . uniqid(),
        'contact_person' => 'U Kyaw',
        'phone' => '0912345678',
        'outstanding_balance' => 0.00,
    ]);
});

/*
|--------------------------------------------------------------------------
| Test 1: Audit Log Listing, Filtering, and Show (CRUD Completeness)
|--------------------------------------------------------------------------
*/

it('can list, filter, and inspect enterprise audit logs', function () {
    $uniqueTag = 'AUDIT_' . uniqid();

    $log = AuditLog::create([
        'staff_id' => $this->adminStaff->id,
        'module' => 'INVENTORY',
        'action' => 'PRICE_OVERRIDE',
        'severity' => 'HIGH',
        'resource_type' => 'Product',
        'resource_id' => $this->product->id,
        'description' => "Manager price override {$uniqueTag}",
        'diffs' => ['old_price' => 40000, 'new_price' => 45000],
        'ip_address' => '127.0.0.1',
    ]);

    // 1. List all audit logs
    $response = $this->getJson('/api/v1/audit-logs');
    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'staff', 'module', 'action', 'severity', 'resourceType', 'resourceId', 'description', 'createdAt']
            ],
            'links',
            'meta',
        ]);

    // 2. Filter by module and search tag
    $filteredResponse = $this->getJson("/api/v1/audit-logs?module=INVENTORY&search={$uniqueTag}");
    $filteredResponse->assertOk();
    expect($filteredResponse->json('data'))->toHaveCount(1)
        ->and($filteredResponse->json('data.0.id'))->toBe($log->id)
        ->and($filteredResponse->json('data.0.module'))->toBe('INVENTORY')
        ->and($filteredResponse->json('data.0.action'))->toBe('PRICE_OVERRIDE');

    // 3. Show individual audit log
    $showResponse = $this->getJson("/api/v1/audit-logs/{$log->id}");
    $showResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $log->id)
        ->assertJsonPath('data.severity', 'HIGH')
        ->assertJsonPath('data.diffs.old_price', 40000);
});

/*
|--------------------------------------------------------------------------
| Test 2: Global Credit Settlements Journal & Filtering
|--------------------------------------------------------------------------
*/

it('can query global credit settlement journal with cashier and customer filtering', function () {
    $customer = Customer::create([
        'name' => 'U Myo Min Settler ' . uniqid(),
        'phone' => '0922334455',
        'credit_limit' => 500000.00,
        'due_amount' => 50000.00,
    ]);

    $receiptNo = 'REC-TEST-' . uniqid();
    $settlement = CreditSettlement::create([
        'receipt_no' => $receiptNo,
        'customer_id' => $customer->id,
        'amount' => 30000.00,
        'tendered_amount' => 30000.00,
        'change_amount' => 0.00,
        'payment_method' => 'Cash',
        'note' => 'Partial debt settlement test',
        'cashier_id' => $this->adminStaff->id,
    ]);

    // 1. Query journal list
    $response = $this->getJson('/api/v1/credit-settlements');
    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'receiptNo', 'customer', 'amount', 'tenderedAmount', 'paymentMethod', 'cashier', 'createdAt']
            ],
            'meta',
        ]);

    // 2. Filter by customer_id and receipt_no search
    $filtered = $this->getJson("/api/v1/credit-settlements?customer_id={$customer->id}&search={$receiptNo}");
    $filtered->assertOk();
    expect($filtered->json('data'))->toHaveCount(1)
        ->and($filtered->json('data.0.receiptNo'))->toBe($receiptNo)
        ->and($filtered->json('data.0.amount'))->toEqual(30000.00);

    // 3. Show single settlement
    $showResponse = $this->getJson("/api/v1/credit-settlements/{$settlement->id}");
    $showResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.receiptNo', $receiptNo)
        ->assertJsonPath('data.customer.id', $customer->id);
});

/*
|--------------------------------------------------------------------------
| Test 3: Draft Purchase Order Editing & Status Guards
|--------------------------------------------------------------------------
*/

it('allows editing draft purchase orders and recalculates totals atomically', function () {
    $po = Purchase::create([
        'po_no' => 'PO-TEST-' . uniqid(),
        'supplier_id' => $this->supplier->id,
        'total_amount' => 30000.00,
        'status' => 'Draft',
        'notes' => 'Original Draft PO',
    ]);

    PurchaseItem::create([
        'purchase_id' => $po->id,
        'product_id' => $this->product->id,
        'qty' => 1,
        'unit_price' => 30000.00,
        'subtotal' => 30000.00,
    ]);

    $newSupplier = Supplier::create([
        'name' => 'New Supplier ' . uniqid(),
        'contact_person' => 'Daw Thuzar',
        'phone' => '0998877665',
    ]);

    // 1. Edit PO: change supplier, notes, and items (qty 5 * 25000 = 125,000)
    $response = $this->putJson("/api/v1/purchases/{$po->id}", [
        'supplier_id' => $newSupplier->id,
        'notes' => 'Updated Draft PO with new quotation',
        'items' => [
            [
                'product_id' => $this->product->id,
                'qty' => 5,
                'unit_price' => 25000.00,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.supplier.id', $newSupplier->id)
        ->assertJsonPath('data.totalAmount', 125000);

    $po->refresh();
    expect($po->supplier_id)->toBe($newSupplier->id)
        ->and((float) $po->total_amount)->toEqual(125000.00)
        ->and($po->items)->toHaveCount(1)
        ->and($po->items->first()->qty)->toBe(5)
        ->and((float) $po->items->first()->unit_price)->toEqual(25000.00);

    // 2. Reject editing once Received
    $po->update(['status' => 'Received']);

    $rejectResponse = $this->putJson("/api/v1/purchases/{$po->id}", [
        'notes' => 'Attempting to change already received PO',
    ]);

    $rejectResponse->assertStatus(422)
        ->assertJsonPath('success', false);
});

/*
|--------------------------------------------------------------------------
| Test 4: Product Variant Update, Delete, and Stock Safeguard
|--------------------------------------------------------------------------
*/

it('updates and deletes individual product variants with physical stock safeguard', function () {
    $this->product->update(['has_variants' => true]);

    $sku = 'VAR-SKU-' . uniqid();
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => $sku,
        'barcode' => 'BAR-' . uniqid(),
        'price' => 50000.00,
        'cost_price' => 35000.00,
        'stock' => 0,
        'is_active' => true,
    ]);

    // 1. Update variant price and active state
    $updateResponse = $this->putJson("/api/v1/products/{$this->product->id}/variants/{$variant->id}", [
        'price' => 55000.00,
        'cost_price' => 38000.00,
        'is_active' => false,
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.price', 55000)
        ->assertJsonPath('data.costPrice', 38000)
        ->assertJsonPath('data.isActive', false);

    $variant->refresh();
    expect((float) $variant->price)->toEqual(55000.00)
        ->and($variant->is_active)->toBeFalse();

    // 2. Block deletion if variant has physical stock > 0
    $variant->update(['stock' => 15]);

    $deleteBlocked = $this->deleteJson("/api/v1/products/{$this->product->id}/variants/{$variant->id}");
    $deleteBlocked->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(ProductVariant::find($variant->id))->not->toBeNull();

    // 3. Allow deletion when stock is zero
    $variant->update(['stock' => 0]);

    $deleteSuccess = $this->deleteJson("/api/v1/products/{$this->product->id}/variants/{$variant->id}");
    $deleteSuccess->assertOk()
        ->assertJsonPath('success', true);

    expect(ProductVariant::find($variant->id))->toBeNull();

    // Product has_variants flag should be updated to false since no variants remain
    $this->product->refresh();
    expect($this->product->has_variants)->toBeFalse();
});
