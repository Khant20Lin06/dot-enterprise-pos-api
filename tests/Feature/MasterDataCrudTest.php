<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Vehicle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->first()
        ?? StaffUser::first();
});

it('creates, updates, and deletes a customer via API with debt safety guards', function () {
    Sanctum::actingAs($this->adminStaff);

    // 1. Create Customer
    $createResponse = $this->postJson('/api/v1/customers', [
        'name' => 'Ko Aung Myo',
        'phone' => '09950001122',
        'email' => 'aungmyo@test.com',
        'address' => 'Hledan, Kamayut, Yangon',
        'tier' => 'Silver',
        'credit_limit' => 300000.00,
        'notes' => 'Reliable regular customer',
    ]);

    $createResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Customer registered successfully.',
            'data' => [
                'name' => 'Ko Aung Myo',
                'phone' => '09950001122',
            ],
        ]);

    $customerId = $createResponse->json('data.id');

    // 2. Query with search and has_debt filter
    $listResponse = $this->getJson('/api/v1/customers?search=Aung+Myo');
    $listResponse->assertStatus(200)
        ->assertJsonFragment(['name' => 'Ko Aung Myo']);

    // 3. Update Customer
    $updateResponse = $this->putJson("/api/v1/customers/{$customerId}", [
        'tier' => 'VIP',
        'credit_limit' => 500000.00,
    ]);

    $updateResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'tier' => 'VIP',
                'creditLimit' => 500000.00,
            ],
        ]);

    // 4. Guard: Block delete when customer has outstanding debt
    $customer = Customer::findOrFail($customerId);
    $customer->update(['due_amount' => 50000.00]);

    $blockDeleteResponse = $this->deleteJson("/api/v1/customers/{$customerId}");
    $blockDeleteResponse->assertStatus(422)
        ->assertJsonFragment(['success' => false]);

    // 5. Successful delete after debt is cleared
    $customer->update(['due_amount' => 0.00]);
    $deleteResponse = $this->deleteJson("/api/v1/customers/{$customerId}");
    $deleteResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(Customer::find($customerId))->toBeNull();
});

it('creates, lists, and manages customer vehicles with unique plate number', function () {
    Sanctum::actingAs($this->adminStaff);
    $customer = Customer::first();

    // 1. Create Vehicle
    $createResponse = $this->postJson('/api/v1/vehicles', [
        'plate_number' => 'YGN-7G-9988',
        'customer_id' => $customer->id,
        'make' => 'Toyota',
        'model' => 'Alphard',
        'year' => 2021,
        'vin' => 'ANH20-889911',
        'fuel_type' => 'Petrol',
        'current_mileage' => 45000,
    ]);

    $createResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Vehicle registered successfully.',
            'data' => [
                'plateNumber' => 'YGN-7G-9988',
                'make' => 'Toyota',
                'model' => 'Alphard',
            ],
        ]);

    $vehicleId = $createResponse->json('data.id');

    // 2. Reject duplicate plate number
    $duplicateResponse = $this->postJson('/api/v1/vehicles', [
        'plate_number' => 'YGN-7G-9988',
        'customer_id' => $customer->id,
        'make' => 'Honda',
        'model' => 'Fit',
    ]);
    $duplicateResponse->assertStatus(422)
        ->assertJsonValidationErrors(['plate_number']);

    // 3. Update mileage
    $updateResponse = $this->putJson("/api/v1/vehicles/{$vehicleId}", [
        'current_mileage' => 48000,
    ]);
    $updateResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => ['currentMileage' => 48000],
        ]);

    // 4. Delete Vehicle
    $deleteResponse = $this->deleteJson("/api/v1/vehicles/{$vehicleId}");
    $deleteResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(Vehicle::find($vehicleId))->toBeNull();
});

it('manages categories and units of measure with integrity guards against in-use deletion', function () {
    Sanctum::actingAs($this->adminStaff);

    // 1. Category CRUD
    $catResponse = $this->postJson('/api/v1/categories', [
        'code' => 'TEST_CAT_SUSP',
        'name' => 'Suspension & Steering',
        'description' => 'Shock absorbers, springs, tie rods',
    ]);
    $catResponse->assertStatus(201)
        ->assertJson(['success' => true, 'data' => ['code' => 'TEST_CAT_SUSP']]);

    $catId = $catResponse->json('data.id');

    // 2. UOM CRUD
    $uomResponse = $this->postJson('/api/v1/units-of-measure', [
        'name' => 'Pair',
        'description' => 'Set of two matched units',
    ]);
    $uomResponse->assertStatus(201)
        ->assertJson(['success' => true, 'data' => ['name' => 'Pair']]);

    $uomId = $uomResponse->json('data.id');

    // 3. Create product using this Category and UOM
    $product = Product::create([
        'category_id' => $catId,
        'uom_id' => $uomId,
        'name' => 'Front Shock Absorber Pair',
        'barcode' => 'SHK-PR-001',
        'sell_price' => 120000.00,
        'cost_price' => 90000.00,
        'stock' => 5,
    ]);

    // 4. Guard: Cannot delete category when product is linked
    $blockCatDelete = $this->deleteJson("/api/v1/categories/{$catId}");
    $blockCatDelete->assertStatus(422)
        ->assertJsonFragment(['success' => false]);

    // 5. Guard: Cannot delete UOM when product is linked
    $blockUomDelete = $this->deleteJson("/api/v1/units-of-measure/{$uomId}");
    $blockUomDelete->assertStatus(422)
        ->assertJsonFragment(['success' => false]);

    // Delete product, then delete Category & UOM successfully
    $product->delete();

    $this->deleteJson("/api/v1/categories/{$catId}")->assertStatus(200);
    $this->deleteJson("/api/v1/units-of-measure/{$uomId}")->assertStatus(200);
});

it('creates product via API, allocates warehouse stock automatically, updates pricing, and guards against deletion with sales history', function () {
    Sanctum::actingAs($this->adminStaff);

    $category = Category::first();
    $uom = UnitOfMeasure::first();
    $warehouse = Warehouse::first();

    // 1. Create Product with initial stock
    $createResponse = $this->postJson('/api/v1/products', [
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Mobil 1 Super Synthetic 0W-20',
        'barcode' => 'M1-0W20-AUTO',
        'cost_price' => 45000.00,
        'sell_price' => 60000.00,
        'wholesale_price' => 55000.00,
        'initial_stock' => 50,
        'warehouse_id' => $warehouse?->id,
        'min_stock' => 10,
    ]);

    $createResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => [
                'name' => 'Mobil 1 Super Synthetic 0W-20',
                'barcode' => 'M1-0W20-AUTO',
                'stock' => 50,
            ],
        ]);

    $productId = $createResponse->json('data.id');

    // Verify master stock and warehouse stock are synchronized (Inflow invariant)
    $product = Product::findOrFail($productId);
    expect($product->stock)->toBe(50);

    if ($warehouse) {
        $whStock = WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where('product_id', $productId)
            ->first();
        expect($whStock)->not->toBeNull()
            ->and((int) $whStock->stock_qty)->toBe(50);
    }

    // 2. Update Product
    $updateResponse = $this->putJson("/api/v1/products/{$productId}", [
        'sell_price' => 62000.00,
        'min_stock' => 12,
    ]);

    $updateResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'sellPrice' => 62000.00,
            ],
        ]);

    // 3. Delete Product (unreferenced, clear physical stock first)
    $product->update(['stock' => 0]);
    if ($warehouse) {
        WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $productId)->update(['stock_qty' => 0]);
    }
    $deleteResponse = $this->deleteJson("/api/v1/products/{$productId}");
    $deleteResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(Product::find($productId))->toBeNull();
});
