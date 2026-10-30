<?php

use App\Domains\CreditAndLedger\Actions\SettleCustomerCreditAction;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Actions\DispatchStockTransferAction;
use App\Domains\Inventory\Actions\ReceiveStockTransferAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\ProductVariant;
use App\Domains\Inventory\Models\StockTransfer;
use App\Domains\Inventory\Models\StockTransferItem;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Models\WarehouseStock;
use App\Domains\SalesAndPos\Actions\ClosePosSessionAction;
use App\Domains\SalesAndPos\Actions\CreatePaymentTransactionAction;
use App\Domains\SalesAndPos\Actions\OpenPosSessionAction;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Actions\ProcessSaleReturnAction;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SalesAndPos\Models\SalesReturnItem;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Actions\ConvertWorkOrderToInvoiceAction;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use App\Domains\Workshop\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| CRIT-01: Payment Webhook Cryptographic Verification & Auth Boundary
|--------------------------------------------------------------------------
*/

it('rejects unauthenticated payment webhook without cryptographic HMAC signature with 403', function () {
    $response = $this->postJson('/api/v1/payments/webhook', [
        'provider' => 'kbzpay',
        'gateway_ref' => 'KBZ-FORGERY-001',
        'status' => 'successful',
        'amount' => 50000.00,
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Missing cryptographic payment gateway signature (X-Signature).');
});

it('rejects payment webhook with tampered or invalid HMAC signature with 403', function () {
    $response = $this->postJson('/api/v1/payments/webhook', [
        'provider' => 'kbzpay',
        'gateway_ref' => 'KBZ-FORGERY-002',
        'status' => 'successful',
        'amount' => 50000.00,
    ], [
        'X-Signature' => 'invalid_forged_hmac_sha256_signature_string',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Invalid cryptographic payment gateway signature.');
});

it('processes cryptographically signed public webhook without auth bearer token and marks transaction successful', function () {
    $createAction = app(CreatePaymentTransactionAction::class);
    $transaction = $createAction->execute([
        'provider' => 'kbzpay',
        'amount' => 45000.00,
        'order_no' => 'INV-WEBHOOK-CRIT01',
    ]);

    $payload = [
        'provider' => 'kbzpay',
        'gateway_ref' => $transaction->gateway_ref,
        'status' => 'successful',
        'amount' => 45000.00,
        'transaction_id' => 'KBZ-TXN-REAL-SECURE-777',
    ];

    $secret = config('services.payment_gateway.webhook_secret', 'dot_enterprise_webhook_secret_key_2026');
    $signature = hash_hmac('sha256', json_encode($payload), $secret);

    // Public call: No Sanctum::actingAs!
    $response = $this->postJson('/api/v1/payments/webhook', $payload, [
        'X-Signature' => $signature,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('verified.status', 'successful');

    expect($transaction->fresh()->status)->toBe('successful');
});

/*
|--------------------------------------------------------------------------
| CRIT-02: Workshop Invoices & Credit Collections Linked to Cashier Shifts
|--------------------------------------------------------------------------
*/

it('automatically links workshop job card invoice to cashier active shift session', function () {
    $cashier = StaffUser::where('email', 'cashier@dotpos.com')->first();
    Sanctum::actingAs($cashier);

    // 1. Open cashier register shift
    $openAction = app(OpenPosSessionAction::class);
    $session = $openAction->execute([
        'terminal_id' => 'WORKSHOP-BAY-01',
        'opening_float' => 50000.00,
    ], $cashier->id);

    // 2. Create and convert work order
    $customer = Customer::first();
    $vehicle = Vehicle::first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-SHIFT-001',
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'complaint' => 'Engine oil replacement and filter maintenance',
        'status' => 'In Progress',
        'parts_total' => $product->retail_price * 2,
        'labor_fee' => 15000.00,
        'total_amount' => ($product->retail_price * 2) + 15000.00,
    ]);

    WorkOrderItem::create([
        'work_order_id' => $workOrder->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'qty' => 2,
        'unit_price' => $product->retail_price,
        'cost_price' => $product->cost_price,
        'subtotal' => $product->retail_price * 2,
    ]);

    $convertAction = app(ConvertWorkOrderToInvoiceAction::class);
    $result = $convertAction->execute($workOrder->id, 'Cash', $cashier->id);

    $salesOrder = $result['sales_order'];

    // CRIT-02 Assertion: Sales Order MUST be linked to cashier active shift
    expect($salesOrder->pos_session_id)->toBe($session->id);
});

it('links customer credit debt cash settlement to cashier shift and reconciles in drawer formula', function () {
    $cashier = StaffUser::where('email', 'cashier@dotpos.com')->first();
    Sanctum::actingAs($cashier);

    // 1. Open Shift
    $openAction = app(OpenPosSessionAction::class);
    $session = $openAction->execute([
        'terminal_id' => 'COUNTER-SETTLE',
        'opening_float' => 100000.00,
    ], $cashier->id);

    // 2. Customer owes 80,000 Ks
    $customer = Customer::first();
    $customer->update(['due_amount' => 80000.00]);

    // 3. Customer pays 50,000 Ks cash towards credit debt
    $settleAction = app(SettleCustomerCreditAction::class);
    $settlement = $settleAction->execute(
        customerId: $customer->id,
        amount: 50000.00,
        paymentMethod: 'Cash',
        note: 'Partial debt repayment',
        cashierId: $cashier->id
    );

    expect($settlement->pos_session_id)->toBe($session->id);

    // 4. Close Shift with expected drawer cash: 100,000 (Float) + 50,000 (Credit Cash) = 150,000 Ks
    $closeAction = app(ClosePosSessionAction::class);
    $closedSession = $closeAction->execute($session, [
        'counted_cash' => 150000.00,
        'closing_notes' => 'Drawer balanced with credit collection',
    ], $cashier->id);

    expect((float) $closedSession->expected_cash)->toBe(150000.00)
        ->and((float) $closedSession->cash_variance)->toBe(0.00);
});

/*
|--------------------------------------------------------------------------
| CRIT-03: Sales Return Multi-Warehouse Location & Product Variant Restock
|--------------------------------------------------------------------------
*/

it('restocks both warehouse location and product variant inventory when processing sales return', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $warehouse = Warehouse::first() ?? Warehouse::create([
        'code' => 'WH-MAIN-TEST',
        'name' => 'Main Test Warehouse',
        'address' => 'Yangon',
        'is_active' => true,
    ]);

    $product = Product::where('is_labor', false)->first();

    // Create a product variant
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'OIL-VAR-TEST',
        'barcode' => '885999000111',
        'price' => 25000.00,
        'cost_price' => 15000.00,
        'stock' => 10,
    ]);

    // Initialize warehouse stock
    WarehouseStock::updateOrCreate(
        ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
        ['stock_qty' => 20]
    );

    // 1. Checkout 2 units of variant
    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'customer_id' => Customer::first()->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'qty' => 2,
                'unit_price' => 25000.00,
            ],
        ],
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => 50000.00],
        ],
    ], $cashier->id);

    expect($variant->fresh()->stock)->toBe(8);

    // 2. Process Return of 1 unit with condition Sellable
    $orderItem = $order->items->first();
    $returnAction = app(ProcessSaleReturnAction::class);

    $salesReturn = $returnAction->execute([
        'sales_order_id' => $order->id,
        'warehouse_id' => $warehouse->id,
        'reason' => 'Customer exchange',
        'refund_method' => 'Cash',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'product_id' => $product->id,
                'qty' => 1,
                'item_condition' => 'Sellable',
            ],
        ],
    ], $cashier->id);

    // CRIT-03 Assertions: Variant stock, warehouse stock, and return item link
    expect($variant->fresh()->stock)->toBe(9)
        ->and(WarehouseStock::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first()->stock_qty)->toBe(19);

    $returnItem = SalesReturnItem::where('sales_return_id', $salesReturn->id)->first();
    expect($returnItem->product_variant_id)->toBe($variant->id);
});

/*
|--------------------------------------------------------------------------
| HIGH-02: Loyalty Points Arbitrage Cash Refund Exploit Protection
|--------------------------------------------------------------------------
*/

it('prevents loyalty points arbitrage exploit by reducing cash refund when customer spent points', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $customer = Customer::where('name', '!=', 'Walk-In Customer')->first();
    $customer->update(['loyalty_points' => 0]);

    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();
    $price = (float) $product->sell_price;

    // 1. Buy 2 units (Total: price * 2) -> earns points (e.g., 50 points if 50,000 Ks)
    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => $price],
        ],
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $price * 2],
        ],
    ], $cashier->id);

    $pointsEarned = $order->points_earned;
    expect($pointsEarned)->toBeGreaterThan(0);

    // Refresh model reference and simulate points arbitrage depletion to 0
    $customer = $customer->fresh();
    expect($customer->loyalty_points)->toBe($pointsEarned);
    $customer->update(['loyalty_points' => 0]);
    expect($customer->fresh()->loyalty_points)->toBe(0);

    // 2. Customer requests refund for 1 unit
    $returnAction = app(ProcessSaleReturnAction::class);
    $salesReturn = $returnAction->execute([
        'sales_order_id' => $order->id,
        'reason' => 'Customer spent points arbitrage attempt',
        'refund_method' => 'Cash',
        'items' => [
            [
                'sales_order_item_id' => $order->items->first()->id,
                'product_id' => $product->id,
                'qty' => 1,
                'item_condition' => 'Sellable',
            ],
        ],
    ], $cashier->id);

    // HIGH-02 Assertion: Customer points stayed at 0 (no negative crash), but refund amount was reduced by cash value of missing points!
    expect($customer->fresh()->loyalty_points)->toBe(0)
        ->and((float) $salesReturn->total_refund_amount)->toBeLessThan($price);
});

/*
|--------------------------------------------------------------------------
| HIGH-03: RBAC Middleware Protection on POS & Staff Endpoints
|--------------------------------------------------------------------------
*/

it('blocks staff members without pos.create permission from checking out', function () {
    // Create a role without pos.create
    $viewerRole = Role::create([
        'code' => 'VIEW_ONLY',
        'name' => 'View Only Auditor',
        'badge_color' => '#6b7280',
        'permissions' => [
            'pos' => ['view' => true, 'create' => false],
            'settings' => ['manageRoles' => false],
        ],
    ]);

    $restrictedStaff = StaffUser::create([
        'name' => 'Auditor Zaw',
        'email' => 'auditor@dotpos.com',
        'phone' => '+959123456789',
        'role_id' => $viewerRole->id,
        'pin_hash' => Hash::make('1111'),
        'password' => Hash::make('password123'),
        'status' => 'Active',
    ]);

    Sanctum::actingAs($restrictedStaff);

    $product = Product::where('is_labor', false)->first();

    $response = $this->postJson('/api/v1/pos/checkout', [
        'customer_id' => Customer::first()->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1],
        ],
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => (float) $product->retail_price],
        ],
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', "Forbidden: Staff member lacks required permission 'pos.create'.");
});

it('blocks staff members without settings.manageRoles permission from viewing staff list', function () {
    $cashier = StaffUser::where('email', 'cashier@dotpos.com')->first();
    Sanctum::actingAs($cashier);

    $response = $this->getJson('/api/v1/staff');

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', "Forbidden: Staff member lacks required permission 'settings.manageRoles'.");
});

/*
|--------------------------------------------------------------------------
| MED-01: Change Amount Recorded on Cash Overpayment
|--------------------------------------------------------------------------
*/

it('calculates and stores change_amount when customer pays more than order total', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $product = Product::where('is_labor', false)->first();
    $product->update(['retail_price' => 85000.00]);

    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'customer_id' => Customer::first()->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 85000.00],
        ],
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => 100000.00], // Customer gave 100,000 Ks note
        ],
    ], $cashier->id);

    // MED-01 Assertion: 15,000 Ks change stored on order
    expect((float) $order->change_amount)->toBe(15000.00)
        ->and((float) $order->total_amount)->toBe(85000.00);
});

/*
|--------------------------------------------------------------------------
| MED-02: Revocation of Stale PIN Session Tokens
|--------------------------------------------------------------------------
*/

it('revokes previous PIN session tokens when cashier unlocks terminal again', function () {
    $staff = StaffUser::where('email', 'cashier@dotpos.com')->first();

    // Unlock 1
    $resp1 = $this->postJson('/api/v1/auth/verify-pin', [
        'email' => $staff->email,
        'pin' => '1234',
    ]);
    $resp1->assertStatus(200);

    expect($staff->tokens()->where('name', 'like', 'pin-session%')->count())->toBe(1);

    // Unlock 2
    $resp2 = $this->postJson('/api/v1/auth/verify-pin', [
        'email' => $staff->email,
        'pin' => '1234',
    ]);
    $resp2->assertStatus(200);

    // MED-02 Assertion: Stale token revoked, exactly 1 active pin-session token exists
    expect($staff->tokens()->where('name', 'like', 'pin-session%')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| MED-03: Lost-in-Transit Stock Transfer Damaged Accounting
|--------------------------------------------------------------------------
*/

it('reconciles lost-in-transit goods as Damage / Scrap and decrements master stock', function () {
    $manager = StaffUser::first();
    $w1 = Warehouse::first() ?? Warehouse::create([
        'code' => 'WH-SRC-TEST',
        'name' => 'Source Test Warehouse',
        'address' => 'Yangon',
        'is_active' => true,
    ]);

    $w2 = Warehouse::where('id', '!=', $w1->id)->first() ?? Warehouse::create([
        'code' => 'WH-DEST-TEST',
        'name' => 'Destination Branch',
        'address' => 'Mandalay Branch',
        'is_active' => true,
    ]);

    $product = Product::where('is_labor', false)->first();
    $initialMasterStock = $product->stock;

    WarehouseStock::updateOrCreate(
        ['warehouse_id' => $w1->id, 'product_id' => $product->id],
        ['stock_qty' => 20]
    );

    // 1. Create and dispatch transfer of 5 units
    $transfer = StockTransfer::create([
        'transfer_no' => 'TR-TEST-LOST',
        'source_warehouse_id' => $w1->id,
        'destination_warehouse_id' => $w2->id,
        'status' => 'Draft',
        'requested_by' => $manager->id,
    ]);

    $item = StockTransferItem::create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'qty_requested' => 5,
    ]);

    $dispatchAction = app(DispatchStockTransferAction::class);
    $dispatched = $dispatchAction->execute($transfer, $manager->id);

    // 2. Receive only 3 units (2 units damaged/lost in transit)
    $receiveAction = app(ReceiveStockTransferAction::class);
    $received = $receiveAction->execute($dispatched, $manager->id, [
        $item->id => 3,
    ]);

    expect($received->status)->toBe('Received');

    // Destination warehouse got 3 units
    $destStock = WarehouseStock::where('warehouse_id', $w2->id)->where('product_id', $product->id)->first();
    expect($destStock->stock_qty)->toBe(3);

    // MED-03 Assertion: Master company stock decreased by 2 lost units
    expect($product->fresh()->stock)->toBe($initialMasterStock - 2);
});
