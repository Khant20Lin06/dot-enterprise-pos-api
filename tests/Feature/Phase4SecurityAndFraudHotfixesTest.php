<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\PricingAndPromotions\Models\Promotion;
use App\Domains\SalesAndPos\Actions\OpenPosSessionAction;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->cashierRole = Role::firstOrCreate(
        ['code' => 'CASHIER_TEST'],
        [
            'name' => 'Cashier Test Role',
            'permissions' => [
                'pos' => ['view' => true, 'create' => true, 'edit' => false, 'applyDiscount' => false],
                'inventory' => ['view' => true, 'adjustStock' => true],
            ],
            'is_system' => false,
        ]
    );

    $this->managerRole = Role::firstOrCreate(
        ['code' => 'MANAGER_TEST'],
        [
            'name' => 'Manager Test Role',
            'permissions' => [
                'pos' => ['view' => true, 'create' => true, 'edit' => true, 'applyDiscount' => true, 'voidInvoice' => true],
            ],
            'is_system' => false,
        ]
    );

    $this->cashier = StaffUser::create([
        'name' => 'Cashier Kyaw',
        'email' => 'cashier.stage1.' . uniqid() . '@dotpos.com',
        'role_id' => $this->cashierRole->id,
        'pin_hash' => Hash::make('1234'),
        'password' => Hash::make('password123'),
        'status' => 'Active',
    ]);

    $this->manager = StaffUser::create([
        'name' => 'Supervisor Daw Hla',
        'email' => 'manager.stage1.' . uniqid() . '@dotpos.com',
        'role_id' => $this->managerRole->id,
        'pin_hash' => Hash::make('9999'),
        'password' => Hash::make('password123'),
        'status' => 'Active',
    ]);

    Sanctum::actingAs($this->cashier);

    $category = Category::first() ?? Category::create(['code' => 'GEN', 'name' => 'General']);
    $uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);

    $this->testProduct = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'Brake Fluid DOT 4 (500ml)',
        'barcode' => 'BF-DOT4-' . uniqid(),
        'sell_price' => 20000.00,
        'cost_price' => 12000.00,
        'stock' => 50,
        'is_labor' => false,
    ]);

    $this->warehouse = Warehouse::first() ?? Warehouse::create(['code' => 'WH-ST1', 'name' => 'Stage 1 Warehouse', 'is_active' => true]);

    WarehouseStock::create([
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->testProduct->id,
        'stock_qty' => 50,
    ]);
});

/*
|--------------------------------------------------------------------------
| Hotfix 1: Order-Level Discretionary Discount Authorization (CRIT-01)
|--------------------------------------------------------------------------
*/

it('blocks arbitrary client-side discounts without manager authorization and allows with PIN or promo', function () {
    // 1. Attempt checkout with unauthorized 5,000 Ks discount
    $unauthResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => Customer::first()->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'discount_amount' => 5000.00,
        'payments' => [
            ['method' => 'Cash', 'amount' => 15000.00],
        ],
    ]);

    $unauthResponse->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
    expect($unauthResponse->json('message'))->toContain('requires manager authorization');

    // 2. Supply valid manager PIN: checkout must succeed!
    $authResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => Customer::first()->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'discount_amount' => 5000.00,
        'manager_pin' => '9999',
        'payments' => [
            ['method' => 'Cash', 'amount' => 15000.00],
        ],
    ]);

    $authResponse->assertStatus(201);
    expect((float) $authResponse->json('data.totalAmount'))->toBe(15000.0)
        ->and((float) $authResponse->json('data.discountAmount'))->toBe(5000.0);

    // 3. Checkout with valid promotional campaign code
    $promo = Promotion::create([
        'code' => 'PROMO-2K-' . uniqid(),
        'title' => '2000 Ks Flat Discount',
        'type' => 'fixed',
        'value' => 2000.00,
        'min_spend' => 15000.00,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(7)->toDateString(),
        'is_active' => true,
        'target_tier' => 'All',
    ]);

    $promoResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => Customer::first()->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'promotion_code' => $promo->code,
        'payments' => [
            ['method' => 'Cash', 'amount' => 18000.00],
        ],
    ]);

    $promoResponse->assertStatus(201);
    expect((float) $promoResponse->json('data.totalAmount'))->toBe(18000.0)
        ->and((float) $promoResponse->json('data.discountAmount'))->toBe(2000.0);
});

/*
|--------------------------------------------------------------------------
| Hotfix 2: Anti Cash-Out Fraud (Credit Cannot Generate Drawer Change) (CRIT-02)
|--------------------------------------------------------------------------
*/

it('blocks cashier cash-out fraud by rejecting credit amounts exceeding order balance', function () {
    $customer = Customer::create([
        'name' => 'Trust Logistics Co',
        'phone' => '0988776655',
        'credit_limit' => 500000.00,
        'due_amount' => 0.00,
    ]);

    // Product is 20,000 Ks. Attacker tenders 120,000 Ks on credit to extract 100,000 Ks cash change.
    $fraudResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Credit', 'amount' => 120000.00],
        ],
    ]);

    $fraudResponse->assertStatus(422)
        ->assertJson(['success' => false]);
    expect($fraudResponse->json('message'))->toContain('cannot exceed remaining unpaid order balance');

    // Legit Credit checkout: 20,000 Ks order fully charged to credit with ZERO change
    $legitResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Credit', 'amount' => 20000.00],
        ],
    ]);

    $legitResponse->assertStatus(201);
    expect((float) $legitResponse->json('data.changeAmount'))->toBe(0.0)
        ->and((float) $legitResponse->json('data.dueAmount'))->toBe(20000.0);

    expect((float) $customer->fresh()->due_amount)->toBe(20000.00);
});

/*
|--------------------------------------------------------------------------
| Hotfix 3: Anonymous Ghost Debt Elimination (CRIT-03)
|--------------------------------------------------------------------------
*/

it('blocks anonymous and walk-in credit sales to eliminate ghost debt', function () {
    // 1. Omit customer_id
    $anonResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => null,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Credit', 'amount' => 20000.00],
        ],
    ]);

    $anonResponse->assertStatus(422)
        ->assertJson(['success' => false]);
    expect($anonResponse->json('message'))->toContain('strictly requires a registered customer');

    // 2. Walk-in guest customer ID 1
    $walkInCustomer = Customer::firstOrCreate(['id' => 1], ['name' => 'Walk-In Customer', 'phone' => '0900000000']);
    $walkInResponse = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => $walkInCustomer->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Credit', 'amount' => 20000.00],
        ],
    ]);

    $walkInResponse->assertStatus(422);
    expect($walkInResponse->json('message'))->toContain('Cannot extend credit to');
});

/*
|--------------------------------------------------------------------------
| Hotfix 4: Mandatory Active Shift Session for Cashiers (CRIT-04)
|--------------------------------------------------------------------------
*/

it('enforces open shift session for cashier checkouts', function () {
    $freshCashier = StaffUser::create([
        'name' => 'No Shift Cashier',
        'email' => 'noshift.' . uniqid() . '@dotpos.com',
        'role_id' => $this->cashierRole->id,
        'pin_hash' => Hash::make('4321'),
        'password' => Hash::make('password123'),
        'status' => 'Active',
    ]);

    Sanctum::actingAs($freshCashier);

    // Attempt checkout with enforce_session flag
    $blockedResponse = $this->postJson('/api/v1/pos/checkout', [
        'enforce_session' => true,
        'customer_id' => Customer::first()->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 20000.00],
        ],
    ]);

    $blockedResponse->assertStatus(422);
    expect($blockedResponse->json('message'))->toContain('active, open cash register shift session');

    // Open Shift Session
    $session = app(OpenPosSessionAction::class)->execute([
        'warehouse_id' => $this->warehouse->id,
        'opening_float' => 50000.00,
    ], $freshCashier->id);

    // Checkout now succeeds
    $successResponse = $this->postJson('/api/v1/pos/checkout', [
        'enforce_session' => true,
        'customer_id' => Customer::first()->id,
        'items' => [
            ['product_id' => $this->testProduct->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 20000.00],
        ],
    ]);

    $successResponse->assertStatus(201)
        ->assertJsonPath('data.posSessionId', $session->id);
});

/*
|--------------------------------------------------------------------------
| Hotfix 5: Stock Adjustment Exception Handling & Voucher Show (BIZ-03)
|--------------------------------------------------------------------------
*/

it('handles negative stock adjustment violations with clean 422 and supports voucher show', function () {
    $admin = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
    Sanctum::actingAs($admin);

    // 1. Negative adjustment beyond available stock
    $crashAttempt = $this->postJson('/api/v1/stock-adjustments', [
        'product_id' => $this->testProduct->id,
        'type' => 'Damage / Scrap',
        'adjusted_qty' => -999, // Stock is only 50
        'reason' => 'Severe flood damage',
    ]);

    $crashAttempt->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
    expect($crashAttempt->json('message'))->toContain('Negative stock violation');

    // 2. Valid Adjustment
    $validAdjustment = $this->postJson('/api/v1/stock-adjustments', [
        'product_id' => $this->testProduct->id,
        'type' => 'Cycle Count',
        'adjusted_qty' => 5,
        'reason' => 'Annual physical count audit',
    ]);

    $validAdjustment->assertStatus(201);
    $adjId = $validAdjustment->json('data.id');

    // 3. Show Adjustment Voucher details
    $showResponse = $this->getJson("/api/v1/stock-adjustments/{$adjId}");
    $showResponse->assertStatus(200)
        ->assertJsonPath('data.id', $adjId)
        ->assertJsonPath('data.adjustedQty', 5)
        ->assertJsonPath('data.type', 'Cycle Count');
});
