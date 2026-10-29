<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\CreditAndLedger\Models\Fleet;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\Mechanic;
use App\Domains\Workshop\Models\WorkOrder;
use App\Domains\Workshop\Models\WorkOrderItem;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Actions\DeductStockAction;
use App\Domains\Inventory\Actions\ReplenishStockAction;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\Workshop\Actions\ConvertWorkOrderToInvoiceAction;
use App\Domains\Workshop\Actions\CalculateMechanicCommissionAction;
use App\Domains\CreditAndLedger\Actions\SettleCustomerCreditAction;
use App\Domains\PricingAndPromotions\Actions\ResolveDynamicPriceAction;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\CreditLimitExceededException;

uses(DatabaseTransactions::class);

it('deducts inventory with pessimistic locking and blocks overselling', function () {
    $product = Product::where('is_labor', false)->where('stock', '>', 5)->first();
    $initialStock = $product->stock;

    $action = app(DeductStockAction::class);
    $updated = $action->execute($product->id, 2);

    expect($updated->stock)->toBe($initialStock - 2);

    // Attempting to buy more than remaining stock must throw InsufficientStockException
    expect(fn () => $action->execute($product->id, $updated->stock + 10))
        ->toThrow(InsufficientStockException::class);
});

it('completes POS checkout, deducts stock, records payment splits, and credits points', function () {
    $cashier = StaffUser::first();
    $customer = Customer::where('name', '!=', 'Walk-In Customer')->first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();
    $initialStock = $product->stock;
    $initialPoints = $customer->loyalty_points;

    $checkoutAction = app(ProcessCheckoutAction::class);

    $payload = [
        'customer_id' => $customer->id,
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
            ['method' => 'Cash', 'amount' => $product->sell_price],
            ['method' => 'KPay', 'amount' => $product->sell_price, 'reference_no' => 'KP-998811'],
        ],
        'idempotency_key' => 'IDEMP-' . uniqid(),
    ];

    $order = $checkoutAction->execute($payload, $cashier->id);

    expect($order)->not->toBeNull();
    expect($order->order_no)->toStartWith('INV-');
    expect($order->status)->toBe('Completed');
    expect($order->payments)->toHaveCount(2);

    // Verify inventory deduction
    expect($product->fresh()->stock)->toBe($initialStock - 2);

    // Verify loyalty points accrued (1 point per 1000 Ks)
    $expectedPoints = (int) floor(($product->sell_price * 2) / 1000);
    expect($customer->fresh()->loyalty_points)->toBe($initialPoints + $expectedPoints);
});

it('guarantees idempotency so duplicate requests do not deduct stock twice', function () {
    $cashier = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 10)->first();
    $initialStock = $product->stock;

    $checkoutAction = app(ProcessCheckoutAction::class);
    $idempotencyKey = 'UNIQUE-KEY-' . uniqid();

    $payload = [
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => $product->sell_price],
        ],
        'total_amount' => $product->sell_price,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $product->sell_price],
        ],
        'idempotency_key' => $idempotencyKey,
    ];

    // First Call
    $order1 = $checkoutAction->execute($payload, $cashier->id);
    expect($product->fresh()->stock)->toBe($initialStock - 1);

    // Duplicate Re-transmitted Call
    $order2 = $checkoutAction->execute($payload, $cashier->id);

    // Must return the exact same order and NOT deduct stock again
    expect($order2->id)->toBe($order1->id);
    expect($product->fresh()->stock)->toBe($initialStock - 1);
});

it('blocks sale when credit payment exceeds customer credit limit', function () {
    $cashier = StaffUser::first();
    $customer = Customer::where('credit_limit', '>', 0)->first();
    $product = Product::where('is_labor', false)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);

    $excessiveAmount = (float) $customer->credit_limit + 100000.00;

    $payload = [
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => $excessiveAmount],
        ],
        'total_amount' => $excessiveAmount,
        'payment_splits' => [
            ['method' => 'Credit', 'amount' => $excessiveAmount],
        ],
    ];

    expect(fn () => $checkoutAction->execute($payload, $cashier->id))
        ->toThrow(CreditLimitExceededException::class);
});

it('converts workshop job card to invoice, automatically deducting part stock and calculating mechanic commission', function () {
    $customer = Customer::first();
    $vehicle = Vehicle::first();
    $mechanic = Mechanic::where('commission_rate', '>', 0)->first();
    $oil = Product::where('barcode', '8851234567890')->first();
    $initialStock = $oil->stock;

    // 1. Create Work Order (In Progress)
    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-ACTION-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'assigned_mechanic_id' => $mechanic->id,
        'bay' => 'Bay 1',
        'complaint' => 'Routine 10,000 km engine maintenance',
        'labor_fee' => 30000.00,
        'parts_total' => $oil->sell_price,
        'total_amount' => 30000.00 + $oil->sell_price,
        'status' => 'In Progress',
    ]);

    WorkOrderItem::create([
        'work_order_id' => $workOrder->id,
        'product_id' => $oil->id,
        'name' => $oil->name,
        'qty' => 1,
        'unit_price' => $oil->sell_price,
        'cost_price' => $oil->cost_price,
        'subtotal' => $oil->sell_price,
    ]);

    // 2. Convert to Invoice
    $convertAction = app(ConvertWorkOrderToInvoiceAction::class);
    $result = $convertAction->execute($workOrder->id, 'Cash', 1);

    // Assert Status is Completed / Invoiced
    expect($result['work_order']->status)->toBe('Completed / Invoiced');
    expect($result['work_order']->sales_order_id)->not->toBeNull();

    // Assert Stock was automatically deducted (Resolving Enterprise Audit Gap!)
    expect($oil->fresh()->stock)->toBe($initialStock - 1);

    // Assert Mechanic Commission was created (20% of 30,000 Ks = 6,000 Ks)
    expect($result['commission'])->not->toBeNull();
    expect($result['commission']->earned_amount)->toEqual(6000.00);
});

it('settles customer debt and decrements due balance with sequential receipt', function () {
    $customer = Customer::where('due_amount', '>', 0)->first();
    $initialDue = (float) $customer->due_amount;

    $settleAction = app(SettleCustomerCreditAction::class);
    $settlement = $settleAction->execute($customer->id, 50000.00, 'KPay', 'Partial settlement payment');

    expect($settlement->receipt_no)->toStartWith('REC-');
    expect($settlement->amount)->toEqual(50000.00);
    expect((float) $customer->fresh()->due_amount)->toBe($initialDue - 50000.00);
});

it('resolves dynamic wholesale and volume break pricing', function () {
    $product = Product::where('wholesale_price', '>', 0)->first();
    $customer = Customer::where('tier', 'VIP')->first();

    $pricingAction = app(ResolveDynamicPriceAction::class);

    // Resolving for VIP / Fleet customer
    $result = $pricingAction->execute($product, 1, $customer->id);

    expect($result['unit_price'])->toBeLessThanOrEqual((float) $product->sell_price);
    expect($result['original_price'])->toEqual((float) $product->sell_price);
});
