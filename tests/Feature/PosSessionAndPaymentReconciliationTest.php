<?php

use App\Domains\Inventory\Models\Product;
use App\Domains\SalesAndPos\Actions\ClosePosSessionAction;
use App\Domains\SalesAndPos\Actions\CreatePaymentTransactionAction;
use App\Domains\SalesAndPos\Actions\GenerateShiftAuditReportAction;
use App\Domains\SalesAndPos\Actions\OpenPosSessionAction;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Events\PosSessionClosedEvent;
use App\Domains\SalesAndPos\Events\PosSessionOpenedEvent;
use App\Domains\SalesAndPos\Models\PaymentTransaction;
use App\Domains\SalesAndPos\Models\PosSession;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Event::fake([PosSessionOpenedEvent::class, PosSessionClosedEvent::class]);
});

it('opens a cash register shift session with opening float via API', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $terminalId = 'TEST-COUNTER-' . time();

    $response = $this->postJson('/api/v1/pos/sessions/open', [
        'terminal_id' => $terminalId,
        'opening_float' => 100000.00,
        'opening_notes' => 'Morning shift startup float',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.terminalId', $terminalId)
        ->assertJsonPath('data.status', 'open');

    expect((float) $response->json('data.openingFloat'))->toEqual(100000.00);

    $this->assertDatabaseHas('pos_sessions', [
        'cashier_id' => $cashier->id,
        'terminal_id' => $terminalId,
        'status' => 'open',
    ]);
});

it('prevents opening multiple active shifts for the same cashier or same terminal', function () {
    $cashier = StaffUser::first();
    $terminalId = 'COUNTER-DUP-' . time();

    $openAction = app(OpenPosSessionAction::class);
    $openAction->execute([
        'terminal_id' => $terminalId,
        'opening_float' => 50000.00,
    ], $cashier->id);

    // 1. Same cashier attempting another open shift
    expect(function () use ($openAction, $cashier) {
        $openAction->execute([
            'terminal_id' => 'COUNTER-OTHER-' . time(),
            'opening_float' => 30000.00,
        ], $cashier->id);
    })->toThrow(DomainException::class);

    // 2. Different cashier attempting to use the already occupied terminal
    $otherCashier = StaffUser::where('id', '!=', $cashier->id)->first();
    expect(function () use ($openAction, $otherCashier, $terminalId) {
        $openAction->execute([
            'terminal_id' => $terminalId,
            'opening_float' => 40000.00,
        ], $otherCashier->id);
    })->toThrow(DomainException::class);
});

it('retrieves current active shift session for authenticated cashier', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $terminalId = 'COUNTER-CURR-' . time();
    $openAction = app(OpenPosSessionAction::class);
    $session = $openAction->execute([
        'terminal_id' => $terminalId,
        'opening_float' => 75000.00,
    ], $cashier->id);

    $response = $this->getJson('/api/v1/pos/sessions/current');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('hasActiveShift', true)
        ->assertJsonPath('data.id', $session->id)
        ->assertJsonPath('data.sessionNo', $session->session_no);
});

it('automatically links pos checkout order to active cashier shift', function () {
    $cashier = StaffUser::first();
    $terminalId = 'COUNTER-LINK-' . time();

    $openAction = app(OpenPosSessionAction::class);
    $session = $openAction->execute([
        'terminal_id' => $terminalId,
        'opening_float' => 100000.00,
    ], $cashier->id);

    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => $product->sell_price,
            ],
        ],
        'subtotal' => $product->sell_price * 2,
        'total_amount' => $product->sell_price * 2,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $product->sell_price * 2],
        ],
    ], $cashier->id);

    expect($order->pos_session_id)->toBe($session->id);
});

it('generates an interim X-Report while shift is still open', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $terminalId = 'COUNTER-XREP-' . time();
    $openAction = app(OpenPosSessionAction::class);
    $session = $openAction->execute([
        'terminal_id' => $terminalId,
        'opening_float' => 100000.00,
    ], $cashier->id);

    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();
    $price = (float) $product->sell_price;

    $checkoutAction = app(ProcessCheckoutAction::class);
    $checkoutAction->execute([
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => $price,
            ],
        ],
        'subtotal' => $price,
        'total_amount' => $price,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $price],
        ],
    ], $cashier->id);

    $response = $this->getJson("/api/v1/pos/sessions/{$session->id}/x-report");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.reportType', 'X-Report')
        ->assertJsonPath('data.isFinal', false)
        ->assertJsonPath('data.financialSummary.ordersCount', 1);

    expect((float) $response->json('data.cashReconciliation.expectedDrawerCash'))->toEqual(100000.00 + $price);
});

it('closes shift session with blind cash count and identifies cash shortage variance', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $terminalId = 'COUNTER-SHORT-' . time();
    $openAction = app(OpenPosSessionAction::class);
    $session = $openAction->execute([
        'terminal_id' => $terminalId,
        'opening_float' => 100000.00,
    ], $cashier->id);

    // Sale of product cash
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();
    $price = (float) $product->sell_price;
    $expectedDrawer = 100000.00 + $price;
    $shortage = 3000.00;
    $counted = $expectedDrawer - $shortage;

    $checkoutAction = app(ProcessCheckoutAction::class);
    $checkoutAction->execute([
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => $price,
            ],
        ],
        'subtotal' => $price,
        'total_amount' => $price,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $price],
        ],
    ], $cashier->id);

    // Cashier does blind count: counts less cash (Shortage of -3,000 Ks)
    $response = $this->postJson("/api/v1/pos/sessions/{$session->id}/close", [
        'counted_cash' => $counted,
        'closing_notes' => 'Minor cash shortage in drawer',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'closed');

    expect((float) $response->json('data.expectedCash'))->toEqual($expectedDrawer);
    expect((float) $response->json('data.countedCash'))->toEqual($counted);
    expect((float) $response->json('data.cashVariance'))->toEqual(-$shortage);

    $session->refresh();
    expect($session->isClosed())->toBeTrue();
    expect((float) $session->cash_variance)->toEqual(-$shortage);
});

it('closes shift session with balanced cash (zero variance) and produces Z-Report', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $terminalId = 'COUNTER-BAL-' . time();
    $openAction = app(OpenPosSessionAction::class);
    $session = $openAction->execute([
        'terminal_id' => $terminalId,
        'opening_float' => 120000.00,
    ], $cashier->id);

    // Sale of split KPay and Cash
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();
    $price = (float) $product->sell_price;
    $cashAmount = round($price / 2, 2);
    $kpayAmount = round($price - $cashAmount, 2);
    $expectedDrawer = 120000.00 + $cashAmount;

    $checkoutAction = app(ProcessCheckoutAction::class);
    $checkoutAction->execute([
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => $price,
            ],
        ],
        'subtotal' => $price,
        'total_amount' => $price,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $cashAmount],
            ['method' => 'KPay', 'amount' => $kpayAmount, 'reference_no' => 'KBZ-12345'],
        ],
    ], $cashier->id);

    $closeAction = app(ClosePosSessionAction::class);
    $closedSession = $closeAction->execute($session, [
        'counted_cash' => $expectedDrawer,
    ], $cashier->id);

    expect((float) $closedSession->cash_variance)->toEqual(0.00);
    expect((float) $closedSession->kpay_sales)->toEqual($kpayAmount);
    expect((float) $closedSession->cash_sales)->toEqual($cashAmount);

    // Generate Z-Report
    $zReportResponse = $this->getJson("/api/v1/pos/sessions/{$session->id}/z-report");
    $zReportResponse->assertStatus(200)
        ->assertJsonPath('data.reportType', 'Z-Report')
        ->assertJsonPath('data.isFinal', true)
        ->assertJsonPath('data.cashReconciliation.varianceStatus', 'Balanced');
});

it('generates dynamic MMQR payment intent for KBZPay and WavePay', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    // 1. KBZPay MMQR Generation
    $kpayResponse = $this->postJson('/api/v1/payments/generate-qr', [
        'provider' => 'kbzpay',
        'amount' => 45000.00,
        'order_no' => 'INV-TEST-001',
    ]);

    $kpayResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.provider', 'kbzpay')
        ->assertJsonPath('data.status', 'pending');

    expect((float) $kpayResponse->json('data.amount'))->toEqual(45000.00);

    expect($kpayResponse->json('data.qrPayload'))->toContain('000201010212');
    expect($kpayResponse->json('data.deeplink'))->toContain('kbzpay://qr?prepay_id=');

    // 2. WavePay QR Generation
    $waveResponse = $this->postJson('/api/v1/payments/generate-qr', [
        'provider' => 'wavepay',
        'amount' => 20000.00,
        'order_no' => 'INV-TEST-002',
    ]);

    $waveResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.provider', 'wavepay');

    expect($waveResponse->json('data.deeplink'))->toContain('wavemoney://payment?order_id=');
});

it('processes simulated gateway webhook callback and marks transaction successful', function () {
    $cashier = StaffUser::first();
    Sanctum::actingAs($cashier);

    $createAction = app(CreatePaymentTransactionAction::class);
    $transaction = $createAction->execute([
        'provider' => 'kbzpay',
        'amount' => 30000.00,
        'order_no' => 'INV-HOOK-001',
    ]);

    $webhookResponse = $this->postJson('/api/v1/payments/simulate-webhook', [
        'provider' => 'kbzpay',
        'gateway_ref' => $transaction->gateway_ref,
        'status' => 'successful',
        'amount' => 30000.00,
        'transaction_id' => 'KBZ-TXN-CONFIRMED-999',
    ]);

    $webhookResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('verified.status', 'successful');

    expect($transaction->fresh()->status)->toBe('successful');
});
