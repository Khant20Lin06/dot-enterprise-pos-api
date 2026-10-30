<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Product;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use App\Jobs\GenerateReceiptPdfJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

it('authenticates staff user with valid email and password', function () {
    $staff = StaffUser::first();
    $staff->update(['password' => Hash::make('secret123'), 'status' => 'Active']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $staff->email,
        'password' => 'secret123',
        'workstation' => 'Counter-01',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'token',
            'user' => ['id', 'name', 'email', 'role'],
        ]);

    expect($response->json('success'))->toBeTrue();
});

it('rejects authentication with invalid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'unknown@example.com',
        'password' => 'wrongpass',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Invalid email or password credentials.',
        ]);
});

it('authenticates cashier terminal with 4-digit PIN code', function () {
    $staff = StaffUser::first();
    $staff->update(['pin_hash' => Hash::make('5566'), 'status' => 'Active']);

    $response = $this->postJson('/api/v1/auth/verify-pin', [
        'pin' => '5566',
        'workstation' => 'Quick-Counter',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonPath('user.id', $staff->id);
});

it('searches and lists catalog products with category and barcode', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $product = Product::first();

    $response = $this->getJson('/api/v1/products?search=' . urlencode(substr($product->name, 0, 5)));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'sku', 'barcode', 'name', 'retailPrice', 'stock'],
            ],
        ]);
});

it('processes POS checkout via API with split tender payments and stock decrement', function () {
    Queue::fake([GenerateReceiptPdfJob::class]);

    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $customer = Customer::first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();
    $initialStock = (float) $product->stock;

    $checkoutPayload = [
        'cashier_id' => $staff->id,
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => (float) $product->retail_price,
            ],
        ],
        'payments' => [
            [
                'method' => 'Cash',
                'amount' => (float) $product->retail_price,
            ],
            [
                'method' => 'KPay',
                'amount' => (float) $product->retail_price,
                'reference_no' => 'KPAY-API-12345',
            ],
        ],
        'idempotency_key' => 'IDEM-API-' . uniqid(),
    ];

    $response = $this->postJson('/api/v1/pos/checkout', $checkoutPayload);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'POS Checkout completed successfully.',
        ])
        ->assertJsonPath('data.status', 'Completed');

    $product->refresh();
    expect((float) $product->stock)->toEqual($initialStock - 2.0);
});

it('creates and manages parked cart sessions (Held Orders)', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $customer = Customer::first();
    $product = Product::first();

    $parkResponse = $this->postJson('/api/v1/pos/held-orders', [
        'title' => 'Customer parked for additional parts',
        'customer_id' => $customer->id,
        'cart_data' => [
            ['productId' => $product->id, 'qty' => 1, 'price' => 10000],
        ],
        'subtotal' => 10000.00,
    ]);

    $parkResponse->assertStatus(201)
        ->assertJson(['success' => true]);

    $heldId = $parkResponse->json('data.id');

    // List held orders
    $listResponse = $this->getJson('/api/v1/pos/held-orders');
    $listResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    // Delete held order
    $deleteResponse = $this->deleteJson("/api/v1/pos/held-orders/{$heldId}");
    $deleteResponse->assertStatus(200)
        ->assertJson(['success' => true, 'message' => 'Parked order released.']);
});

it('creates automotive Job Card and converts it to official POS invoice', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $customer = Customer::first();
    $vehicle = Vehicle::first();
    $mechanic = Mechanic::first();
    $part = Product::where('is_labor', false)->where('stock', '>=', 3)->first();
    $initialStock = (float) $part->stock;

    // 1. Create Job Card
    $createResponse = $this->postJson('/api/v1/workshop/work-orders', [
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'service_advisor_id' => $staff->id,
        'primary_mechanic_id' => $mechanic->id,
        'mileage_in' => 45000,
        'fuel_level' => '1/2',
        'complaint' => 'Front suspension clunking sound on bumps',
        'items' => [
            [
                'product_id' => $part->id,
                'item_type' => 'part',
                'name' => $part->name,
                'qty' => 1,
                'unit_price' => (float) $part->retail_price,
            ],
        ],
    ]);

    $createResponse->assertStatus(201)
        ->assertJson(['success' => true]);

    $jobCardId = $createResponse->json('data.id');

    // 2. Convert to Invoice
    $convertResponse = $this->postJson("/api/v1/workshop/work-orders/{$jobCardId}/convert-to-invoice");

    $convertResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Job Card successfully converted to official POS invoice.',
        ]);

    $part->refresh();
    expect((float) $part->stock)->toEqual($initialStock - 1.0);
});

it('settles customer credit debt via API and returns ledger', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $customer = Customer::first();
    $customer->update(['due_amount' => 50000.00]);

    $settleResponse = $this->postJson("/api/v1/customers/{$customer->id}/settle-credit", [
        'amount' => 20000.00,
        'payment_method' => 'KPay',
        'note' => 'Partial debt settlement via KPay',
    ]);

    $settleResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Customer credit debt settled successfully.',
        ]);

    expect((float) $settleResponse->json('data.customer.currentDue'))->toEqual(30000.00);

    // Verify ledger endpoint
    $ledgerResponse = $this->getJson("/api/v1/customers/{$customer->id}/ledger");
    $ledgerResponse->assertStatus(200)
        ->assertJson(['success' => true]);
});

it('retrieves daily sales and workshop executive summary report via API', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $response = $this->getJson('/api/v1/reports/daily-summary?date=' . now()->toDateString());

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'date',
                'pos' => ['total_orders', 'gross_sales', 'collected_amount'],
                'workshop' => ['job_cards_invoiced'],
            ],
        ]);
});
