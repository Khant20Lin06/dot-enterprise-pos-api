<?php

use App\Domains\Inventory\Models\Attribute;
use App\Domains\Inventory\Models\AttributeValue;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductUomConversion;
use App\Domains\Inventory\Models\ProductVariant;
use App\Domains\Inventory\Models\ProductVehicleFitment;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Actions\ProcessPurchaseReturnAction;
use App\Domains\Purchasing\Models\Purchase;
use App\Domains\Purchasing\Models\PurchaseItem;
use App\Domains\Purchasing\Models\PurchaseReturn;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($this->adminStaff);

    $this->category = Category::first() ?? Category::create(['code' => 'SPARE', 'name' => 'Spare Parts']);
    $this->uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);
    $this->boxUom = UnitOfMeasure::where('code', 'BOX')->first()
        ?? UnitOfMeasure::create(['code' => 'BOX', 'name' => 'Box', 'symbol' => 'bx']);
});

/*
|--------------------------------------------------------------------------
| Test 1: Attribute & AttributeValue Updates & Variant Delete Guards
|--------------------------------------------------------------------------
*/

it('updates and deletes attributes with variant relational guards', function () {
    // 1. Create Attribute
    $attribute = Attribute::create([
        'code' => 'COLOR_' . uniqid(),
        'name' => 'Color',
        'description' => 'Vehicle exterior coating color',
    ]);

    $valRed = $attribute->values()->create(['value' => 'Ruby Red', 'sort_order' => 1]);
    $valBlue = $attribute->values()->create(['value' => 'Cobalt Blue', 'sort_order' => 2]);

    // 2. Update Attribute definition
    $updateRes = $this->putJson("/api/v1/attributes/{$attribute->id}", [
        'name' => 'Automotive Exterior Paint Color',
        'description' => 'Certified OEM metallic coatings',
    ]);

    $updateRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Automotive Exterior Paint Color');

    // 3. Link Ruby Red to a ProductVariant
    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Bumper Spray Paint ' . uniqid(),
        'barcode' => 'BSP-' . uniqid(),
        'sell_price' => 18000.00,
        'cost_price' => 10000.00,
        'stock' => 10,
        'has_variants' => true,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'SKU-RED-' . uniqid(),
        'barcode' => 'BAR-RED-' . uniqid(),
        'price' => 18000.00,
        'cost_price' => 10000.00,
        'stock' => 10,
        'is_active' => true,
    ]);

    $variant->attributeValues()->attach($valRed->id);

    // 4. Attempt to delete attribute while value is linked to active variant -> expect 422
    $failDeleteRes = $this->deleteJson("/api/v1/attributes/{$attribute->id}");
    $failDeleteRes->assertStatus(422)
        ->assertJsonPath('success', false);

    // 5. Create unreferenced attribute and delete it -> expect 200 OK
    $freeAttribute = Attribute::create([
        'code' => 'FINISH_' . uniqid(),
        'name' => 'Finish Type',
    ]);
    $freeAttribute->values()->create(['value' => 'Gloss']);

    $successDeleteRes = $this->deleteJson("/api/v1/attributes/{$freeAttribute->id}");
    $successDeleteRes->assertOk()
        ->assertJsonPath('success', true);

    expect(Attribute::find($freeAttribute->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Test 2: Dedicated AttributeValue CRUD & Active Variant Guards
|--------------------------------------------------------------------------
*/

it('manages individual attribute values with standalone CRUD and variant guards', function () {
    $attribute = Attribute::create([
        'code' => 'SIZE_' . uniqid(),
        'name' => 'Tire Rim Size',
    ]);

    // 1. Add standalone value
    $createValRes = $this->postJson("/api/v1/attributes/{$attribute->id}/values", [
        'value' => '17 Inch Alloy',
        'color_hex' => '#CCCCCC',
        'sort_order' => 1,
    ]);

    $createValRes->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.value', '17 Inch Alloy');

    $valId = $createValRes->json('data.id');

    // 2. Update value (fix typo / customize sort)
    $updateValRes = $this->putJson("/api/v1/attributes/{$attribute->id}/values/{$valId}", [
        'value' => '17-Inch Machined Diamond',
        'sort_order' => 5,
    ]);

    $updateValRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.value', '17-Inch Machined Diamond')
        ->assertJsonPath('data.sortOrder', 5);

    // 3. Link value to a ProductVariant
    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Sport Alloy Wheel ' . uniqid(),
        'barcode' => 'SAW-' . uniqid(),
        'sell_price' => 350000.00,
        'cost_price' => 240000.00,
        'stock' => 4,
        'has_variants' => true,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'WHL-17-' . uniqid(),
        'barcode' => 'WHL-BAR-' . uniqid(),
        'price' => 350000.00,
        'cost_price' => 240000.00,
        'stock' => 4,
        'is_active' => true,
    ]);

    $variant->attributeValues()->attach($valId);

    // 4. Attempt to delete value in use -> expect 422
    $failDeleteRes = $this->deleteJson("/api/v1/attributes/{$attribute->id}/values/{$valId}");
    $failDeleteRes->assertStatus(422)
        ->assertJsonPath('success', false);

    // 5. Add second unlinked value and delete it -> expect 200 OK
    $freeVal = $attribute->values()->create(['value' => '18 Inch Steel', 'sort_order' => 10]);
    $successDeleteRes = $this->deleteJson("/api/v1/attributes/{$attribute->id}/values/{$freeVal->id}");
    $successDeleteRes->assertOk()
        ->assertJsonPath('success', true);

    expect(AttributeValue::find($freeVal->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Test 3: ProductVehicleFitment Updates
|--------------------------------------------------------------------------
*/

it('updates automotive vehicle fitment mapping rules', function () {
    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'High-Performance Brake Pads ' . uniqid(),
        'barcode' => 'BP-' . uniqid(),
        'sell_price' => 45000.00,
        'cost_price' => 28000.00,
        'stock' => 20,
    ]);

    $fitment = ProductVehicleFitment::create([
        'product_id' => $product->id,
        'make' => 'Toyota',
        'model' => 'Alphard',
        'year_from' => 2018,
        'year_to' => 2021,
        'engine_code' => '2AR-FE',
        'notes' => 'Executive Lounge front axle',
    ]);

    $response = $this->putJson("/api/v1/products/{$product->id}/fitments/{$fitment->id}", [
        'year_to' => 2024,
        'notes' => 'Updated ACES fitment: compatible up to 2024 facelift models',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.yearTo', 2024)
        ->assertJsonPath('data.notes', 'Updated ACES fitment: compatible up to 2024 facelift models');

    $fitment->refresh();
    expect($fitment->year_to)->toBe(2024)
        ->and($fitment->notes)->toBe('Updated ACES fitment: compatible up to 2024 facelift models');
});

/*
|--------------------------------------------------------------------------
| Test 4: ProductUomConversion Updates & Bulk Pricing Adjustments
|--------------------------------------------------------------------------
*/

it('updates packaging UOM conversion rules and bulk pricing', function () {
    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Synthetic Motor Oil 1L ' . uniqid(),
        'barcode' => 'MO-1L-' . uniqid(),
        'sell_price' => 15000.00,
        'cost_price' => 9500.00,
        'stock' => 120,
    ]);

    $conversion = ProductUomConversion::create([
        'product_id' => $product->id,
        'uom_id' => $this->boxUom->id,
        'conversion_factor' => 10.00,
        'sell_price' => 140000.00,
        'cost_price' => 90000.00,
        'barcode' => 'BOX-10-' . uniqid(),
        'unit_name' => 'Box of 10 Bottles',
    ]);

    $response = $this->putJson("/api/v1/products/{$product->id}/uom-conversions/{$conversion->id}", [
        'conversion_factor' => 12.00,
        'sell_price' => 165000.00,
        'cost_price' => 108000.00,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.conversionFactor', 12)
        ->assertJsonPath('data.sellPrice', 165000);

    $conversion->refresh();
    expect((float) $conversion->conversion_factor)->toBe(12.0)
        ->and((float) $conversion->sell_price)->toBe(165000.0);
});

/*
|--------------------------------------------------------------------------
| Test 5: Purchase Return Lifecycle Transitions (Claimed -> Dispatched -> Credited / Replaced / Cancelled)
|--------------------------------------------------------------------------
*/

it('manages purchase return lifecycle status transitions with stock and payable adjustments', function () {
    $supplier = Supplier::create([
        'name' => 'Denso Parts Distributor ' . uniqid(),
        'phone' => '0911223344',
        'outstanding_balance' => 500000.00,
    ]);

    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Alternator Assembly ' . uniqid(),
        'barcode' => 'ALT-' . uniqid(),
        'sell_price' => 120000.00,
        'cost_price' => 80000.00,
        'stock' => 50,
    ]);

    $po = Purchase::create([
        'po_no' => 'PO-' . uniqid(),
        'supplier_id' => $supplier->id,
        'total_amount' => 800000.00,
        'status' => 'Received',
    ]);

    PurchaseItem::create([
        'purchase_id' => $po->id,
        'product_id' => $product->id,
        'qty' => 10,
        'unit_price' => 80000.00,
        'subtotal' => 800000.00,
    ]);

    // 1. Issue Purchase Return: returns 2 units (160,000 Ks) -> stock drops to 48, supplier AP drops to 340,000 Ks
    $returnAction = app(ProcessPurchaseReturnAction::class);
    $purchaseReturn = $returnAction->execute([
        'purchase_id' => $po->id,
        'product_id' => $product->id,
        'qty' => 2,
        'amount' => 160000.00,
        'reason' => 'Defective voltage regulator',
    ], $this->adminStaff->id);

    $product->refresh();
    $supplier->refresh();
    expect($purchaseReturn->status)->toBe('Claimed')
        ->and($product->stock)->toBe(48)
        ->and((float) $supplier->outstanding_balance)->toBe(340000.0);

    // 2. Transition Claimed -> Dispatched
    $dispatchRes = $this->patchJson("/api/v1/purchases/returns/{$purchaseReturn->id}/status", [
        'status' => 'Dispatched',
        'notes' => 'Handed over to Denso logistics courier',
    ]);

    $dispatchRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'Dispatched');

    // 3. Transition Dispatched -> Credited
    $creditedRes = $this->putJson("/api/v1/purchases/returns/{$purchaseReturn->id}", [
        'status' => 'Credited',
        'notes' => 'Supplier credit note #CN-9921 received and ledger finalized',
    ]);

    $creditedRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'Credited');

    // 4. Verify terminal status rejection (Cannot transition from Credited)
    $failTerminalRes = $this->patchJson("/api/v1/purchases/returns/{$purchaseReturn->id}/status", [
        'status' => 'Cancelled',
    ]);
    $failTerminalRes->assertStatus(422)
        ->assertJsonPath('success', false);

    // 5. Test Replacement Transition Flow (Vendor replaces defective units -> stock restocked)
    $purchaseReturn2 = $returnAction->execute([
        'purchase_id' => $po->id,
        'product_id' => $product->id,
        'qty' => 1,
        'amount' => 80000.00,
        'reason' => 'Damaged bearing casing',
    ], $this->adminStaff->id);

    $product->refresh();
    expect($product->stock)->toBe(47);

    // Vendor sends fresh replacement goods
    $replaceRes = $this->patchJson("/api/v1/purchases/returns/{$purchaseReturn2->id}/status", [
        'status' => 'Replaced',
        'notes' => 'Received brand new replacement alternator with fresh serial number',
    ]);

    $replaceRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'Replaced');

    $product->refresh();
    $supplier->refresh();
    // Stock is restored to 48 and supplier payable debt restored by 80,000 Ks
    expect($product->stock)->toBe(48)
        ->and((float) $supplier->outstanding_balance)->toBe(340000.0);
});

/*
|--------------------------------------------------------------------------
| Test 6: StockTransfer & StockTransferItem Variant Matrix Alignment
|--------------------------------------------------------------------------
*/

it('dispatches and receives stock transfers with product variants across warehouses', function () {
    $whMain = Warehouse::create([
        'code' => 'MAIN-HUB-' . uniqid(),
        'name' => 'Central Logistics Hub',
        'is_central' => true,
        'is_active' => true,
    ]);

    $whBranch = Warehouse::create([
        'code' => 'BR-NORTH-' . uniqid(),
        'name' => 'North Branch Counter',
        'is_central' => false,
        'is_active' => true,
    ]);

    $product = Product::create([
        'category_id' => $this->category->id,
        'uom_id' => $this->uom->id,
        'name' => 'Racing Clutch Disc ' . uniqid(),
        'barcode' => 'RCD-' . uniqid(),
        'sell_price' => 150000.00,
        'cost_price' => 95000.00,
        'stock' => 30,
        'has_variants' => true,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'CLUTCH-STAGE3-' . uniqid(),
        'barcode' => 'BAR-CLUTCH-' . uniqid(),
        'price' => 165000.00,
        'cost_price' => 105000.00,
        'stock' => 20,
        'is_active' => true,
    ]);

    // Initial stock at central warehouse
    WarehouseStock::create([
        'warehouse_id' => $whMain->id,
        'product_id' => $product->id,
        'stock_qty' => 30,
    ]);

    // 1. Create Stock Transfer order with variant
    $createTransferRes = $this->postJson('/api/v1/stock-transfers', [
        'source_warehouse_id' => $whMain->id,
        'destination_warehouse_id' => $whBranch->id,
        'notes' => 'Branch replenishment for weekend race tuning',
        'items' => [
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'qty' => 5,
                'notes' => 'Stage 3 Ceramic disc variant',
            ],
        ],
    ]);

    $createTransferRes->assertCreated()
        ->assertJsonPath('success', true);

    $transferId = $createTransferRes->json('data.id');

    // 2. Dispatch Transfer: Variant stock must decrement from 20 to 15
    $dispatchRes = $this->postJson("/api/v1/stock-transfers/{$transferId}/dispatch");
    $dispatchRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'In Transit');

    $variant->refresh();
    expect($variant->stock)->toBe(15);

    // 3. Receive Transfer: Branch receives the 5 units, variant stock incremented at destination
    $receiveRes = $this->postJson("/api/v1/stock-transfers/{$transferId}/receive");
    $receiveRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'Received');

    $variant->refresh();
    expect($variant->stock)->toBe(20);

    // Verify StockTransferResource item exposes variant info
    $showRes = $this->getJson("/api/v1/stock-transfers/{$transferId}");
    $showRes->assertOk()
        ->assertJsonPath('data.items.0.productVariantId', (string) $variant->id)
        ->assertJsonPath('data.items.0.variantSku', $variant->sku);
});
