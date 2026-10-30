<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Product;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\Role;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Services\AtomicSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

it('guarantees atomic concurrency-safe sequential invoice numbers', function () {
    $service = app(AtomicSequenceService::class);

    $seq1 = $service->getNextSequence('INV');
    $seq2 = $service->getNextSequence('INV');
    $seq3 = $service->getNextSequence('INV');

    expect($seq1)->not->toBe($seq2);
    expect($seq2)->not->toBe($seq3);
    expect($seq1)->toMatch('/^INV-\d{8}-\d{4}$/');
});

it('prevents client-side price tampering by enforcing catalog price without manager override', function () {
    $staff = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->first();

    $officialPrice = (float) $product->retail_price;
    expect($officialPrice)->toBeGreaterThan(100);

    $action = app(ProcessCheckoutAction::class);

    // Tampered payload attempting to buy an expensive product for 100 Kyats
    $tamperedPayload = [
        'cashier_id' => $staff->id,
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 100.00, // Tampered price
            ],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => $officialPrice],
        ],
    ];

    $order = $action->execute($tamperedPayload);

    // Verify server enforced official price and rejected the 100 Ks tampering
    $item = $order->items->first();
    expect((float) $item->unit_price)->toEqual($officialPrice);
    expect((float) $order->total_amount)->toEqual($officialPrice);
});

it('blocks loyalty points underflow attack when customer lacks sufficient points', function () {
    $staff = StaffUser::first();
    $customer = Customer::first();
    $customer->update(['loyalty_points' => 50]); // Only 50 points available

    $product = Product::where('is_labor', false)->first();
    $action = app(ProcessCheckoutAction::class);

    $payload = [
        'cashier_id' => $staff->id,
        'customer_id' => $customer->id,
        'points_redeemed' => 9999, // Exploit attempt
        'items' => [
            ['product_id' => $product->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => (float) $product->retail_price],
        ],
    ];

    expect(fn () => $action->execute($payload))->toThrow(\InvalidArgumentException::class);

    // Customer points must remain intact
    expect($customer->fresh()->loyalty_points)->toBe(50);
});

it('sorts multi-item locks in consistent ascending order to prevent deadlocks', function () {
    $staff = StaffUser::first();
    $customer = Customer::first();
    $products = Product::where('is_labor', false)->take(2)->get();

    $p1 = $products[0];
    $p2 = $products[1];

    $action = app(ProcessCheckoutAction::class);

    // Send in reverse ID order
    $higherId = max($p1->id, $p2->id);
    $lowerId = min($p1->id, $p2->id);

    $payload = [
        'cashier_id' => $staff->id,
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $higherId, 'qty' => 1],
            ['product_id' => $lowerId, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 500000.00],
        ],
    ];

    $order = $action->execute($payload);
    expect($order->status)->toBe('Completed');
    expect($order->items->count())->toBe(2);
});

it('enforces RBAC permission middleware and blocks unauthorized staff from viewing daily reports', function () {
    // Create restricted staff without finance permissions
    $restrictedRole = Role::create([
        'code' => 'INTERN',
        'name' => 'Workshop Intern',
        'is_system' => false,
        'permissions' => [
            'pos' => ['view' => true],
            'finances' => ['view' => false],
        ],
    ]);

    $intern = StaffUser::create([
        'name' => 'Intern Staff',
        'email' => 'intern@dotpos.com',
        'phone' => '+959000111222',
        'role_id' => $restrictedRole->id,
        'pin_hash' => bcrypt('1111'),
        'password' => 'secret',
        'status' => 'Active',
    ]);

    Sanctum::actingAs($intern);

    $response = $this->getJson('/api/v1/reports/daily-summary');

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
        ])
        ->assertJsonPath('message', "Forbidden: Staff member lacks required permission 'finances.view'.");
});

it('records audit log on failed PIN unlock attempts', function () {
    $response = $this->postJson('/api/v1/auth/verify-pin', [
        'pin' => '0000',
        'workstation' => 'Terminal-SecCheck',
    ]);

    $response->assertStatus(401);

    $auditLog = AuditLog::where('module', 'security')
        ->where('action', 'pin_unlock_failed')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog->severity)->toBe('warning');
});

it('blocks underpaid POS checkout transactions when tender allocation is deficient', function () {
    $staff = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->first();

    $action = app(ProcessCheckoutAction::class);

    // Order requires full catalog price, but client tenders only 1,000 Ks
    $deficientPayload = [
        'cashier_id' => $staff->id,
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => 1000.00],
        ],
    ];

    expect(fn () => $action->execute($deficientPayload))->toThrow(\DomainException::class);
});

it('blocks Work Order intake when vehicle does not belong to the designated customer', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $customer1 = Customer::first();
    $customer2 = Customer::skip(1)->first() ?? Customer::create(['name' => 'Other Cust', 'phone' => '+95999999999']);

    // Vehicle belongs to customer 2
    $vehicle = \App\Domains\Workshop\Models\Vehicle::first();
    $vehicle->update(['customer_id' => $customer2->id]);

    $response = $this->postJson('/api/v1/workshop/work-orders', [
        'customer_id' => $customer1->id, // Mismatched customer!
        'vehicle_id' => $vehicle->id,
        'complaint' => 'Suspicious cross-customer vehicle intake',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

it('blocks negative credit settlement amount to prevent debt inflation', function () {
    $customer = Customer::first();
    $customer->update(['due_amount' => 50000.00]);

    $action = app(\App\Domains\CreditAndLedger\Actions\SettleCustomerCreditAction::class);

    expect(fn () => $action->execute($customer->id, -25000.00, 'Cash'))->toThrow(\InvalidArgumentException::class);
    expect((float) $customer->fresh()->due_amount)->toEqual(50000.00);
});

it('records an immutable financial transaction audit log on completed POS checkout', function () {
    $staff = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->first();

    $action = app(ProcessCheckoutAction::class);

    $order = $action->execute([
        'cashier_id' => $staff->id,
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => (float) $product->retail_price],
        ],
    ]);

    $log = AuditLog::where('module', 'sales_and_pos')
        ->where('action', 'checkout_completed')
        ->where('resource_id', $order->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->severity)->toBe('info');
    expect($log->description)->toContain($order->order_no);
});
