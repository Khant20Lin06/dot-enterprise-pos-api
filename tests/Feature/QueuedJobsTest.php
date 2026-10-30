<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Product;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Models\PaymentSplit;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\MechanicCommission;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\WorkOrder;
use App\Jobs\DispatchThermalPrintJob;
use App\Jobs\GenerateReceiptPdfJob;
use App\Jobs\SendDailySalesSummaryJob;
use App\Services\NodeWorkerBridgeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

it('dispatches GenerateReceiptPdfJob on queue receipts when POS checkout completes', function () {
    Queue::fake([GenerateReceiptPdfJob::class]);

    $staff = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);

    $order = $checkoutAction->execute([
        'cashier_id' => $staff->id,
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
            ],
        ],
        'payments' => [
            ['method' => 'Cash', 'amount' => (float) $product->retail_price],
        ],
    ]);

    Queue::assertPushed(GenerateReceiptPdfJob::class, function ($job) use ($order) {
        return $job->orderId === $order->id && $job->queue === 'receipts';
    });
});

it('executes GenerateReceiptPdfJob, generates receipt artifacts and audit logs', function () {
    $staff = StaffUser::first();
    $customer = Customer::first();
    $vehicle = Vehicle::first();
    $product = Product::where('is_labor', false)->first();

    // Create a realistic order
    $orderNo = 'ORD-QUEUE-' . uniqid();
    $order = SalesOrder::create([
        'order_no' => $orderNo,
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle?->id,
        'cashier_id' => $staff->id,
        'subtotal' => 25000.00,
        'labor_total' => 5000.00,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'total_amount' => 30000.00,
        'paid_amount' => 30000.00,
        'due_amount' => 0.00,
        'status' => 'Completed',
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'qty' => 1,
        'unit_price' => 25000.00,
        'cost_price' => 18000.00,
        'subtotal' => 25000.00,
    ]);

    $order->payments()->create([
        'method' => 'KPay',
        'amount' => 30000.00,
        'reference_no' => 'KPAY-TEST-999',
    ]);

    $job = new GenerateReceiptPdfJob($order->id);
    $bridge = app(NodeWorkerBridgeService::class);
    $job->handle($bridge);

    // Verify file generated in storage
    $jsonFile = storage_path("app/receipts/receipt_{$orderNo}.json");
    $txtFile = storage_path("app/receipts/receipt_{$orderNo}.txt");

    expect(File::exists($jsonFile))->toBeTrue();
    expect(File::exists($txtFile))->toBeTrue();

    $content = json_decode(File::get($jsonFile), true);
    expect($content['order_no'])->toBe($orderNo);
    expect((float) $content['total_amount'])->toEqual(30000.0);

    // Verify AuditLog record
    $log = AuditLog::where('resource_type', 'sales_orders')
        ->where('resource_id', $order->id)
        ->where('action', 'receipt_generated')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->severity)->toBe('info');

    // Clean up test file
    File::delete([$jsonFile, $txtFile]);
});

it('handles GenerateReceiptPdfJob failure and logs critical audit alert', function () {
    $fakeOrderId = 9999999;
    $job = new GenerateReceiptPdfJob($fakeOrderId);
    $exception = new Exception('Thermal printer offline or timeout');

    $job->failed($exception);

    $log = AuditLog::where('resource_type', 'sales_orders')
        ->where('resource_id', $fakeOrderId)
        ->where('action', 'receipt_generation_failed')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->severity)->toBe('critical');
    expect($log->description)->toContain('Thermal printer offline');
});

it('compiles daily sales summary report and logs audit entry', function () {
    $today = now()->toDateString();
    $staff = StaffUser::first();
    $customer = Customer::first();
    $vehicle = Vehicle::first();
    $mechanic = Mechanic::first();

    // 1. Create completed sales order for today
    $order = SalesOrder::create([
        'order_no' => 'ORD-SUM-' . uniqid(),
        'customer_id' => $customer->id,
        'cashier_id' => $staff->id,
        'subtotal' => 50000.00,
        'labor_total' => 10000.00,
        'discount_amount' => 5000.00,
        'tax_amount' => 0.00,
        'total_amount' => 55000.00,
        'paid_amount' => 55000.00,
        'due_amount' => 0.00,
        'status' => 'Completed',
        'created_at' => now(),
    ]);

    PaymentSplit::create([
        'sales_order_id' => $order->id,
        'method' => 'Cash',
        'amount' => 55000.00,
        'created_at' => now(),
    ]);

    // 2. Create invoiced work order
    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-SUM-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle?->id ?? 1,
        'service_advisor_id' => $staff->id,
        'primary_mechanic_id' => $mechanic->id,
        'status' => 'invoiced',
        'complaint' => 'Brake service and general maintenance',
        'parts_total' => 20000.00,
        'labor_total' => 15000.00,
        'grand_total' => 35000.00,
        'updated_at' => now(),
    ]);

    // 3. Create mechanic commission
    MechanicCommission::create([
        'mechanic_id' => $mechanic->id,
        'work_order_id' => $workOrder->id,
        'labor_amount' => 15000.00,
        'commission_rate' => 10.00,
        'earned_amount' => 1500.00,
        'status' => 'pending',
        'created_at' => now(),
    ]);

    $job = new SendDailySalesSummaryJob($today);
    $summary = $job->handle();

    expect($summary['date'])->toBe($today);
    expect($summary['pos']['gross_sales'])->toBeGreaterThanOrEqual(55000.00);
    expect($summary['workshop']['job_cards_invoiced'])->toBeGreaterThanOrEqual(1);
    expect($summary['workshop']['commissions_earned'])->toBeGreaterThanOrEqual(1500.00);

    $reportFile = storage_path("app/reports/sales_summary_{$today}.json");
    expect(File::exists($reportFile))->toBeTrue();

    // Verify AuditLog
    $log = AuditLog::where('module', 'reporting')
        ->where('action', 'daily_sales_summary_compiled')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    // Clean up
    File::delete($reportFile);
});

it('verifies NodeWorkerBridgeService fallback for barcode and ASCII receipt', function () {
    $bridge = app(NodeWorkerBridgeService::class);

    // Barcode generation fallback
    $barcodeResult = $bridge->generateBarcode('PROD-OIL-5W30', 'code128');
    expect($barcodeResult)->toBeArray();
    expect($barcodeResult)->toHaveKey('base64');

    // ASCII receipt formatting
    $staff = StaffUser::first();
    $customer = Customer::first();
    $order = SalesOrder::create([
        'order_no' => 'ORD-ASCII-' . uniqid(),
        'customer_id' => $customer->id,
        'cashier_id' => $staff->id,
        'subtotal' => 15000.00,
        'labor_total' => 0.00,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'total_amount' => 15000.00,
        'paid_amount' => 15000.00,
        'due_amount' => 0.00,
        'status' => 'Completed',
    ]);

    $ascii = $bridge->formatAsciiReceipt($order);
    expect($ascii)->toContain('DOT AUTO WORKSHOP & POS');
    expect($ascii)->toContain($order->order_no);
});

it('verifies Horizon authorization gate handles admin users', function () {
    $admin = StaffUser::where('email', 'admin@dotpos.com')->first();
    if (! $admin) {
        $adminRole = \App\Domains\SecurityAndAudit\Models\Role::firstOrCreate(
            ['code' => 'ADMIN'],
            ['name' => 'Super Administrator', 'permissions' => []]
        );
        $admin = StaffUser::create([
            'name' => 'Super Admin',
            'email' => 'admin@dotpos.com',
            'phone' => '+959111222333',
            'role_id' => $adminRole->id,
            'pin_hash' => 'hash',
            'password' => 'secret',
            'status' => 'Active',
        ]);
    }

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
});
