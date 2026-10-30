<?php

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Actions\ProcessSaleReturnAction;
use App\Domains\SalesAndPos\Events\SalesReturnProcessedEvent;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SalesAndPos\Models\SalesReturn;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Event::fake([SalesReturnProcessedEvent::class]);
});

it('processes a sales return with sellable restock via domain action', function () {
    $cashier = StaffUser::first();
    $customer = Customer::where('name', '!=', 'Walk-In Customer')->first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 10)->first();

    $initialStock = $product->stock;
    $initialPoints = $customer->loyalty_points;

    // 1. Create a sale of 4 units
    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 4,
                'unit_price' => $product->sell_price,
            ],
        ],
        'subtotal' => $product->sell_price * 4,
        'total_amount' => $product->sell_price * 4,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $product->sell_price * 4],
        ],
    ], $cashier->id);

    expect($product->fresh()->stock)->toBe($initialStock - 4);
    $orderItem = $order->items->first();

    // 2. Return 2 units with condition 'Sellable'
    $returnAction = app(ProcessSaleReturnAction::class);
    $salesReturn = $returnAction->execute([
        'sales_order_id' => $order->id,
        'reason' => 'Customer bought wrong viscosity oil',
        'refund_method' => 'Cash',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'product_id' => $product->id,
                'qty' => 2,
                'item_condition' => 'Sellable',
            ],
        ],
    ], $cashier->id);

    // 3. Verify Return Record
    expect($salesReturn)->toBeInstanceOf(SalesReturn::class)
        ->and($salesReturn->return_no)->toStartWith('RET-')
        ->and($salesReturn->status)->toBe('Restocked')
        ->and((float) $salesReturn->total_refund_amount)->toBe((float) round($product->sell_price * 2, 2))
        ->and($salesReturn->items)->toHaveCount(1);

    // 4. Verify Stock Restocked from (initial - 4) back to (initial - 2)
    expect($product->fresh()->stock)->toBe($initialStock - 2);

    // 5. Verify Event Dispatched
    Event::assertDispatched(SalesReturnProcessedEvent::class);
});

it('quarantines defective items without restocking sellable inventory', function () {
    $cashier = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
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
            ['method' => 'Cash', 'amount' => $product->sell_price * 2],
        ],
    ], $cashier->id);

    $stockAfterSale = $product->fresh()->stock;
    $orderItem = $order->items->first();

    // Return 1 unit with condition 'Defective'
    $returnAction = app(ProcessSaleReturnAction::class);
    $salesReturn = $returnAction->execute([
        'sales_order_id' => $order->id,
        'reason' => 'Broken seal and oil leak',
        'refund_method' => 'Cash',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'product_id' => $product->id,
                'qty' => 1,
                'item_condition' => 'Defective',
            ],
        ],
    ], $cashier->id);

    // Stock must NOT be restocked to sellable inventory
    expect($product->fresh()->stock)->toBe($stockAfterSale);

    // Stock Adjustment record must be logged
    $adjustment = StockAdjustment::where('product_id', $product->id)
        ->where('type', 'Damage / Scrap')
        ->latest('id')
        ->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment->reason)->toContain('Sales Return damaged item quarantine');
});

it('adjusts customer credit debt on credit adjusted refund', function () {
    $cashier = StaffUser::first();
    $customer = Customer::where('name', '!=', 'Walk-In Customer')->first();
    $customer->update(['due_amount' => 200000]); // 200,000 Ks debt

    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);
    $totalSaleAmount = $product->sell_price * 2;
    $order = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => $product->sell_price,
            ],
        ],
        'subtotal' => $totalSaleAmount,
        'total_amount' => $totalSaleAmount,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $totalSaleAmount],
        ],
    ], $cashier->id);

    $orderItem = $order->items->first();
    $refundExpected = (float) $product->sell_price;

    // Return 1 item with Credit Adjusted
    $returnAction = app(ProcessSaleReturnAction::class);
    $salesReturn = $returnAction->execute([
        'sales_order_id' => $order->id,
        'reason' => 'Adjust customer account credit',
        'refund_method' => 'Credit Adjusted',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'product_id' => $product->id,
                'qty' => 1,
                'item_condition' => 'Sellable',
            ],
        ],
    ], $cashier->id);

    // Customer due must decrease by 1 item's price (from 200,000 down by refund)
    expect((float) $customer->fresh()->current_due)->toBe(200000.0 - $refundExpected);
});

it('blocks return when quantity exceeds purchased amount', function () {
    $cashier = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => $product->sell_price,
            ],
        ],
        'subtotal' => $product->sell_price,
        'total_amount' => $product->sell_price,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $product->sell_price],
        ],
    ], $cashier->id);

    $orderItem = $order->items->first();
    $returnAction = app(ProcessSaleReturnAction::class);

    // Attempt to return 2 when only 1 was purchased
    expect(fn () => $returnAction->execute([
        'sales_order_id' => $order->id,
        'reason' => 'Fraud attempt',
        'refund_method' => 'Cash',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'product_id' => $product->id,
                'qty' => 2,
                'item_condition' => 'Sellable',
            ],
        ],
    ], $cashier->id))->toThrow(InvalidArgumentException::class, 'Maximum returnable quantity is 1');
});

it('marks the sales order as Returned when all items are fully returned', function () {
    $cashier = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
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
            ['method' => 'Cash', 'amount' => $product->sell_price * 2],
        ],
    ], $cashier->id);

    expect($order->status)->toBe('Completed');

    $orderItem = $order->items->first();
    $returnAction = app(ProcessSaleReturnAction::class);

    // Return all 2 items
    $returnAction->execute([
        'sales_order_id' => $order->id,
        'reason' => 'Complete return',
        'refund_method' => 'Cash',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'product_id' => $product->id,
                'qty' => 2,
                'item_condition' => 'Sellable',
            ],
        ],
    ], $cashier->id);

    // Sales Order status must now be 'Returned'
    expect($order->fresh()->status)->toBe('Returned');
});

it('processes sales return via REST API endpoint with authentication', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $customer = Customer::first();
    $product = Product::where('is_labor', false)->where('stock', '>=', 5)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);
    $order = $checkoutAction->execute([
        'customer_id' => $customer->id,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 3,
                'unit_price' => $product->sell_price,
            ],
        ],
        'subtotal' => $product->sell_price * 3,
        'total_amount' => $product->sell_price * 3,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $product->sell_price * 3],
        ],
    ], $staff->id);

    $orderItem = $order->items->first();

    $response = $this->postJson('/api/v1/sales-returns', [
        'sales_order_id' => $order->id,
        'reason' => 'Customer requested refund at counter',
        'refund_method' => 'Cash',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->id,
                'qty' => 1,
                'item_condition' => 'Sellable',
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'id',
                'returnNo',
                'totalRefundAmount',
                'refundMethod',
                'status',
                'items' => [
                    '*' => ['id', 'salesOrderItemId', 'productId', 'qty', 'unitPrice', 'refundSubtotal', 'itemCondition', 'restocked'],
                ],
            ],
        ]);

    expect($response->json('data.status'))->toBe('Restocked');
});

it('lists and filters sales returns via REST API endpoint', function () {
    $staff = StaffUser::first();
    Sanctum::actingAs($staff);

    $response = $this->getJson('/api/v1/sales-returns');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'returnNo', 'totalRefundAmount', 'refundMethod', 'status'],
            ],
        ]);
});
