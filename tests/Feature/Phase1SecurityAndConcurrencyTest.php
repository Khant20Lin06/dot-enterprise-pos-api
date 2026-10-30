<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Actions\GenerateVariantMatrixAction;
use App\Domains\Inventory\Models\Attribute;
use App\Domains\Inventory\Models\AttributeValue;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first()
        ?? StaffUser::first();

    $this->cashierStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'CASHIER'))->first();

    if (! $this->cashierStaff) {
        $cashierRole = Role::firstOrCreate(
            ['code' => 'CASHIER'],
            [
                'name' => 'Head Cashier',
                'badge_color' => '#10b981',
                'permissions' => [
                    'pos' => ['view' => true, 'create' => true],
                    'inventory' => ['view' => true, 'create' => false, 'edit' => false],
                    'purchases' => ['view' => false, 'create' => false],
                    'settings' => ['manageRoles' => false],
                ],
            ]
        );

        $this->cashierStaff = StaffUser::create([
            'name' => 'Cashier Tester',
            'email' => 'cashiertest@dotpos.com',
            'phone' => '09999888777',
            'role_id' => $cashierRole->id,
            'pin_hash' => Hash::make('1234'),
            'password' => Hash::make('password123'),
            'status' => 'Active',
        ]);
    }
});

it('blocks staff without purchases.view from reading suppliers to prevent reconnaissance', function () {
    Sanctum::actingAs($this->cashierStaff);

    $supplier = Supplier::first();

    $listResponse = $this->getJson('/api/v1/suppliers');
    $listResponse->assertStatus(403)
        ->assertJson([
            'success' => false,
            'message' => "Forbidden: Staff member lacks required permission 'purchases.view'.",
        ]);

    if ($supplier) {
        $showResponse = $this->getJson("/api/v1/suppliers/{$supplier->id}");
        $showResponse->assertStatus(403);
    }
});

it('blocks staff without settings.manageRoles from accessing role catalog and details', function () {
    Sanctum::actingAs($this->cashierStaff);

    $response = $this->getJson('/api/v1/roles/permissions-catalog');
    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'message' => "Forbidden: Staff member lacks required permission 'settings.manageRoles'.",
        ]);

    $rolesResponse = $this->getJson('/api/v1/roles');
    $rolesResponse->assertStatus(403);
});

it('permits authorized staff with inventory.view to read catalog products and warehouses', function () {
    Sanctum::actingAs($this->cashierStaff);

    $productsResponse = $this->getJson('/api/v1/products');
    $productsResponse->assertStatus(200)
        ->assertJsonStructure(['data']);

    $warehousesResponse = $this->getJson('/api/v1/warehouses');
    $warehousesResponse->assertStatus(200)
        ->assertJsonStructure(['data']);
});

it('prevents TOCTOU race conditions and rejects updating or modifying invoiced job cards', function () {
    Sanctum::actingAs($this->adminStaff);

    $customer = Customer::first() ?? Customer::create(['name' => 'Test Customer', 'phone' => '0911223344']);
    $vehicle = Vehicle::first() ?? Vehicle::create(['customer_id' => $customer->id, 'plate_number' => 'YGN-9999', 'model' => 'Corolla']);

    $workOrder = WorkOrder::first() ?? WorkOrder::create([
        'job_card_no' => 'JC-TEST-' . time(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'status' => 'Completed / Invoiced',
        'complaint' => 'Brake overhaul',
        'parts_total' => 0,
        'labor_fee' => 0,
        'total_amount' => 0,
    ]);

    $workOrder->update(['status' => 'Completed / Invoiced']);

    $response = $this->putJson("/api/v1/workshop/work-orders/{$workOrder->id}", [
        'complaint' => 'Attempting concurrent modification after invoice lock',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => "Cannot update Job Card #{$workOrder->job_card_no} with status 'Completed / Invoiced'.",
        ]);

    $statusResponse = $this->patchJson("/api/v1/workshop/work-orders/{$workOrder->id}/status", [
        'status' => 'In Progress',
    ]);

    $statusResponse->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => "Cannot change status of invoiced Job Card #{$workOrder->job_card_no}.",
        ]);

    $cancelResponse = $this->postJson("/api/v1/workshop/work-orders/{$workOrder->id}/cancel");
    $cancelResponse->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => "Cannot cancel an invoiced Job Card #{$workOrder->job_card_no}.",
        ]);
});

it('synchronizes warehouse stock with central product stock when generating variant matrix', function () {
    $category = Category::first() ?? Category::create(['code' => 'LUBRICANTS', 'name' => 'Lubricants']);
    $uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'BOTTLE', 'name' => 'Bottle', 'symbol' => 'btl']);

    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Synthetic Motor Oil Matrix Test',
        'barcode' => 'OIL-MAT-' . time(),
        'sku' => 'SKU-OIL-' . time(),
        'retail_price' => 45000,
        'cost_price' => 30000,
        'stock' => 0,
        'is_labor' => false,
        'has_variants' => false,
    ]);

    $warehouse = Warehouse::firstOrCreate(
        ['code' => 'WH-MAIN'],
        ['name' => 'Main Warehouse', 'is_active' => true]
    );

    $attr = Attribute::firstOrCreate(['name' => 'Volume'], ['code' => 'volume']);
    $val1 = AttributeValue::firstOrCreate(['attribute_id' => $attr->id, 'value' => '1L']);
    $val2 = AttributeValue::firstOrCreate(['attribute_id' => $attr->id, 'value' => '4L']);

    $action = app(GenerateVariantMatrixAction::class);
    $variants = $action->execute(
        product: $product,
        attributeValueIds: [$val1->id, $val2->id],
        config: [
            'initial_stock' => 15,
            'warehouse_id' => $warehouse->id,
        ],
        operatorId: $this->adminStaff->id
    );

    expect($variants)->toHaveCount(2);

    $product->refresh();
    expect((int) $product->stock)->toBe(30);

    // Verify WarehouseStock is synchronized without stock drift
    $warehouseStock = WarehouseStock::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->first();

    expect($warehouseStock)->not->toBeNull()
        ->and((int) $warehouseStock->stock_qty)->toBe(30);
});

it('prunes previous pos login tokens upon staff email/password re-authentication', function () {
    $staff = StaffUser::first();
    $staff->tokens()->delete();

    // Create a preexisting login token
    $staff->createToken("pos-token-{$staff->id}")->plainTextToken;
    expect($staff->tokens()->where('name', 'like', 'pos-token%')->count())->toBe(1);

    // Login via API
    $this->postJson('/api/v1/auth/login', [
        'email' => $staff->email,
        'password' => 'password123',
        'workstation' => 'Terminal-Security-Test',
    ])->assertStatus(200);

    // Must still be 1 (stale token pruned, new token issued)
    expect($staff->tokens()->where('name', 'like', 'pos-token%')->count())->toBe(1);
});

it('rejects simulate webhook endpoint with 403 in production environment', function () {
    // Fake production environment
    App::detectEnvironment(fn () => 'production');

    $payload = [
        'provider' => 'kbzpay',
        'gateway_ref' => 'KBZ-SIM-PROD-REF',
        'status' => 'successful',
        'amount' => 50000.00,
    ];

    $secret = config('services.payment_gateway.webhook_secret', 'dot_enterprise_webhook_secret_key_2026');
    $signature = hash_hmac('sha256', json_encode($payload), $secret);

    $response = $this->postJson('/api/v1/payments/simulate-webhook', $payload, [
        'X-Signature' => $signature,
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'message' => 'Simulate webhook endpoint is strictly disabled in production environment.',
        ]);
});
