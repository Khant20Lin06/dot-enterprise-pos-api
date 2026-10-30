<?php

use App\Domains\CreditAndLedger\Actions\SettleCustomerCreditAction;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Category;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SalesAndPos\Actions\ClosePosSessionAction;
use App\Domains\SalesAndPos\Actions\OpenPosSessionAction;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Models\HeldOrder;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->adminStaff = StaffUser::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first()
        ?? StaffUser::first();

    $this->supervisorRole = Role::firstOrCreate(
        ['code' => 'SUPERVISOR'],
        [
            'name' => 'Floor Supervisor',
            'badge_color' => '#f59e0b',
            'permissions' => [
                'pos' => ['view' => true, 'create' => true, 'voidInvoice' => true],
                'inventory' => ['view' => true],
                'purchases' => ['view' => true],
                'settings' => ['manageRoles' => false],
            ],
        ]
    );

    $this->cashierRole = Role::firstOrCreate(
        ['code' => 'CASHIER'],
        [
            'name' => 'Cashier Staff',
            'badge_color' => '#10b981',
            'permissions' => [
                'pos' => ['view' => true, 'create' => true, 'voidInvoice' => false],
                'inventory' => ['view' => true],
                'purchases' => ['view' => false],
                'settings' => ['manageRoles' => false],
            ],
        ]
    );

    $this->supervisorStaff = StaffUser::firstOrCreate(
        ['email' => 'supervisor_phase2@dotpos.com'],
        [
            'name' => 'Supervisor Daw Hla',
            'phone' => '09888111222',
            'role_id' => $this->supervisorRole->id,
            'pin_hash' => Hash::make('8899'),
            'password' => Hash::make('password123'),
            'status' => 'Active',
            'workstation' => 'Supervisor-Station',
        ]
    );

    $this->cashierA = StaffUser::firstOrCreate(
        ['email' => 'cashier_a_phase2@dotpos.com'],
        [
            'name' => 'Cashier A',
            'phone' => '09111222333',
            'role_id' => $this->cashierRole->id,
            'pin_hash' => Hash::make('1111'),
            'password' => Hash::make('password123'),
            'status' => 'Active',
            'workstation' => 'Counter-01',
        ]
    );

    $this->cashierB = StaffUser::firstOrCreate(
        ['email' => 'cashier_b_phase2@dotpos.com'],
        [
            'name' => 'Cashier B',
            'phone' => '09444555666',
            'role_id' => $this->cashierRole->id,
            'pin_hash' => Hash::make('2222'),
            'password' => Hash::make('password123'),
            'status' => 'Active',
            'workstation' => 'Counter-02',
        ]
    );

    $this->warehouse = Warehouse::firstOrCreate(
        ['code' => 'WH-P2'],
        ['name' => 'Phase 2 Test Warehouse', 'is_active' => true]
    );
});

/*
|--------------------------------------------------------------------------
| P2_1: Customer Credit Settlement Overpayment Handling
|--------------------------------------------------------------------------
*/

it('calculates and records change_amount when customer tenders more than outstanding debt', function () {
    Sanctum::actingAs($this->adminStaff);

    $customer = Customer::create([
        'name' => 'U Ba Thein (Overpayment Test)',
        'phone' => '09777888999',
        'due_amount' => 60000.00,
        'credit_limit' => 200000.00,
    ]);

    // Customer owes 60,000 Ks, but hands a 100,000 Ks bill to cashier
    $response = $this->postJson("/api/v1/customers/{$customer->id}/settle-credit", [
        'amount' => 100000.00,
        'payment_method' => 'Cash',
        'note' => 'Paying 60k debt with 100k note',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'change_due' => 40000.00,
                'settlement' => [
                    'amount' => '60000.00',
                    'tendered_amount' => '100000.00',
                    'change_amount' => '40000.00',
                ],
            ],
        ]);

    $customer->refresh();
    expect((float) $customer->due_amount)->toBe(0.00);
});

it('reconciles credit settlement cash correctly in drawer float formula upon shift close', function () {
    $openSessionAction = app(OpenPosSessionAction::class);
    $closeSessionAction = app(ClosePosSessionAction::class);
    $settleAction = app(SettleCustomerCreditAction::class);

    $session = $openSessionAction->execute([
        'terminal_id' => 'COUNTER-01',
        'warehouse_id' => $this->warehouse->id,
        'opening_float' => 50000.00,
    ], $this->cashierA->id);

    $customer = Customer::create([
        'name' => 'Daw Mya (Drawer Float Reconcile)',
        'phone' => '09123123123',
        'due_amount' => 40000.00,
    ]);

    // Settle 40k debt with 50k cash (10k change returned)
    $settleAction->execute(
        customerId: $customer->id,
        amount: 50000.00,
        paymentMethod: 'Cash',
        note: 'Settling with 50k cash',
        cashierId: $this->cashierA->id,
        posSessionId: $session->id
    );

    // Expected cash in drawer = Opening (50k) + Net Credit Settled (40k) = 90,000 Ks
    $closedSession = $closeSessionAction->execute($session, [
        'counted_cash' => 90000.00,
    ], $this->cashierA->id);

    expect((float) $closedSession->expected_cash)->toBe(90000.00)
        ->and((float) $closedSession->cash_variance)->toBe(0.00);
});

/*
|--------------------------------------------------------------------------
| P2_2: Official POS Order Void Action (Supervisor Authorized)
|--------------------------------------------------------------------------
*/

it('blocks non-supervisor cashiers from voiding completed orders without supervisor pin', function () {
    Sanctum::actingAs($this->cashierA);

    $order = SalesOrder::first() ?? SalesOrder::create([
        'order_no' => 'INV-VOID-GUARD-01',
        'customer_id' => Customer::first()->id,
        'cashier_id' => $this->cashierA->id,
        'subtotal' => 10000,
        'total_amount' => 10000,
        'paid_amount' => 10000,
        'status' => 'Completed',
    ]);

    $response = $this->postJson("/api/v1/pos/orders/{$order->id}/void", [
        'reason' => 'Cashier trying to void without supervisor permission',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Unauthorized: Voiding an order requires supervisor or manager authorization.',
        ]);
});

it('officially voids an erroneous sales order under supervisor authorization, restoring stock and reversing credit', function () {
    Sanctum::actingAs($this->cashierA);

    $openSessionAction = app(OpenPosSessionAction::class);
    $checkoutAction = app(ProcessCheckoutAction::class);

    $session = $openSessionAction->execute([
        'terminal_id' => 'COUNTER-01',
        'warehouse_id' => $this->warehouse->id,
        'opening_float' => 20000.00,
    ], $this->cashierA->id);

    $category = Category::first() ?? Category::create(['code' => 'PARTS', 'name' => 'Parts']);
    $uom = UnitOfMeasure::first() ?? UnitOfMeasure::create(['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pc']);

    $product = Product::create([
        'category_id' => $category->id,
        'uom_id' => $uom->id,
        'name' => 'High Performance Brake Fluid',
        'barcode' => 'BF-VOID-' . time(),
        'sku' => 'SKU-BF-' . time(),
        'retail_price' => 15000.00,
        'cost_price' => 9000.00,
        'stock' => 20,
        'is_labor' => false,
    ]);

    WarehouseStock::create([
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $product->id,
        'stock_qty' => 20,
    ]);

    $customer = Customer::create([
        'name' => 'Ko Zaw Zaw (Order Void Test)',
        'phone' => '09333444555',
        'due_amount' => 0.00,
        'loyalty_points' => 50,
        'credit_limit' => 100000.00,
    ]);

    // 1. Process Checkout: Buy 2 units (30,000 Ks total) — 10k Cash + 20k Credit
    $order = $checkoutAction->execute([
        'cashier_id' => $this->cashierA->id,
        'pos_session_id' => $session->id,
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 15000.00,
            ],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 10000.00],
            ['method' => 'Credit', 'amount' => 20000.00],
        ],
    ], $this->cashierA->id);

    // Verify stock was decremented
    expect((int) $product->fresh()->stock)->toBe(18)
        ->and((int) WarehouseStock::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->value('stock_qty'))->toBe(18);

    // Verify credit debt was added to customer
    expect((float) $customer->fresh()->due_amount)->toBe(20000.00);

    // 2. Void Order via Supervisor PIN
    $voidResponse = $this->postJson("/api/v1/pos/orders/{$order->id}/void", [
        'reason' => 'Cashier punched wrong item accidentally',
        'supervisor_pin' => '8899', // Matches Supervisor Daw Hla PIN
    ]);

    $voidResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'Voided',
                'orderNo' => $order->order_no,
            ],
        ]);

    $order->refresh();
    expect($order->status)->toBe('Voided')
        ->and($order->void_reason)->toBe('Cashier punched wrong item accidentally')
        ->and($order->voided_by)->toBe($this->supervisorStaff->id);

    // Verify inventory restored atomically
    expect((int) $product->fresh()->stock)->toBe(20)
        ->and((int) WarehouseStock::where('warehouse_id', $this->warehouse->id)->where('product_id', $product->id)->value('stock_qty'))->toBe(20);

    // Verify customer credit debt reversed
    expect((float) $customer->fresh()->due_amount)->toBe(0.00);

    // 3. Trying to void again must fail
    $repeatVoid = $this->postJson("/api/v1/pos/orders/{$order->id}/void", [
        'reason' => 'Repeat void attempt',
        'supervisor_pin' => '8899',
    ]);

    $repeatVoid->assertStatus(422)
        ->assertJsonFragment(['success' => false]);
});

it('rejects voiding an order from a closed shift session and mandates sales return', function () {
    Sanctum::actingAs($this->adminStaff);

    $openSessionAction = app(OpenPosSessionAction::class);
    $closeSessionAction = app(ClosePosSessionAction::class);

    $session = $openSessionAction->execute([
        'terminal_id' => 'CENTRAL',
        'warehouse_id' => $this->warehouse->id,
        'opening_float' => 0.00,
    ], $this->adminStaff->id);

    $order = SalesOrder::create([
        'order_no' => 'INV-HIST-CLOSED-01',
        'pos_session_id' => $session->id,
        'customer_id' => Customer::first()->id,
        'cashier_id' => $this->adminStaff->id,
        'subtotal' => 25000.00,
        'total_amount' => 25000.00,
        'paid_amount' => 25000.00,
        'status' => 'Completed',
    ]);

    // Close session
    $closeSessionAction->execute($session, ['counted_cash' => 0.00], $this->adminStaff->id);

    $response = $this->postJson("/api/v1/pos/orders/{$order->id}/void", [
        'reason' => 'Trying to void closed shift transaction',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => "Cannot void Order #{$order->order_no} from a closed shift session. Please process an official Sales Return instead for accounting compliance.",
        ]);
});

/*
|--------------------------------------------------------------------------
| P2_3: Held Orders (Parked Carts) Shift Session & Cashier Scoping
|--------------------------------------------------------------------------
*/

it('isolates held orders to the active cashier and blocks unauthorized cart deletion', function () {
    // 1. Cashier A parks an order
    Sanctum::actingAs($this->cashierA);

    $parkResponseA = $this->postJson('/api/v1/pos/held-orders', [
        'title' => "Cashier A Cart #1",
        'cart_data' => [
            ['product_id' => 1, 'qty' => 1, 'unit_price' => 5000],
        ],
        'subtotal' => 5000.00,
    ]);

    $parkResponseA->assertStatus(201);
    $heldOrderAId = $parkResponseA->json('data.id');

    // 2. Cashier B parks an order
    Sanctum::actingAs($this->cashierB);

    $parkResponseB = $this->postJson('/api/v1/pos/held-orders', [
        'title' => "Cashier B Cart #1",
        'cart_data' => [
            ['product_id' => 1, 'qty' => 2, 'unit_price' => 5000],
        ],
        'subtotal' => 10000.00,
    ]);

    $parkResponseB->assertStatus(201);

    // 3. Cashier B lists held orders: should see Cashier B's cart, not Cashier A's
    $listResponseB = $this->getJson('/api/v1/pos/held-orders');
    $listResponseB->assertStatus(200);

    $titles = collect($listResponseB->json('data'))->pluck('title');
    expect($titles)->toContain('Cashier B Cart #1')
        ->and($titles)->not->toContain('Cashier A Cart #1');

    // 4. Cashier B attempts to delete Cashier A's parked order: must be blocked (403)
    $deleteResponse = $this->deleteJson("/api/v1/pos/held-orders/{$heldOrderAId}");
    $deleteResponse->assertStatus(403)
        ->assertJson([
            'success' => false,
            'message' => 'Unauthorized: You can only release or delete parked orders created by your terminal session.',
        ]);

    // 5. Cashier A can delete their own order
    Sanctum::actingAs($this->cashierA);
    $ownerDelete = $this->deleteJson("/api/v1/pos/held-orders/{$heldOrderAId}");
    $ownerDelete->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Parked order released.',
        ]);
});
