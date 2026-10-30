<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Broadcast;
use App\Domains\Inventory\Actions\DeductStockAction;
use App\Domains\Inventory\Events\StockUpdatedEvent;
use App\Domains\Inventory\Models\Product;
use App\Domains\SalesAndPos\Actions\ProcessCheckoutAction;
use App\Domains\SalesAndPos\Events\OrderCompletedEvent;
use App\Domains\SecurityAndAudit\Models\StaffUser;
use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Workshop\Actions\ConvertWorkOrderToInvoiceAction;
use App\Domains\Workshop\Events\WorkOrderStatusChangedEvent;
use App\Domains\Workshop\Models\WorkOrder;
use App\Domains\Workshop\Models\WorkOrderItem;
use App\Domains\Workshop\Models\Vehicle;
use App\Domains\Workshop\Models\Mechanic;

uses(DatabaseTransactions::class);

it('broadcasts StockUpdatedEvent on stock.channel when inventory is deducted', function () {
    Event::fake([StockUpdatedEvent::class]);

    $product = Product::where('is_labor', false)->first();
    $action = app(DeductStockAction::class);

    $action->execute($product->id, 1);

    Event::assertDispatched(StockUpdatedEvent::class, function (StockUpdatedEvent $event) use ($product) {
        $channels = $event->broadcastOn();
        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('stock.channel');
        expect($event->broadcastAs())->toBe('stock.updated');

        $payload = $event->broadcastWith();
        expect($payload['product_id'])->toBe($product->id);
        expect($payload['delta'])->toBe(-1);
        expect($payload['reason'])->toBe('SALE');

        return true;
    });
});

it('broadcasts OrderCompletedEvent on pos.orders when POS checkout completes', function () {
    Event::fake([StockUpdatedEvent::class, OrderCompletedEvent::class]);

    $cashier = StaffUser::first();
    $customer = Customer::first();
    $product = Product::where('is_labor', false)->first();

    $checkoutAction = app(ProcessCheckoutAction::class);

    $checkoutAction->execute([
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => $product->sell_price],
        ],
        'total_amount' => $product->sell_price,
        'payment_splits' => [
            ['method' => 'Cash', 'amount' => $product->sell_price],
        ],
    ], $cashier->id);

    Event::assertDispatched(OrderCompletedEvent::class, function (OrderCompletedEvent $event) {
        $channels = $event->broadcastOn();
        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('pos.orders');
        expect($event->broadcastAs())->toBe('order.completed');

        $payload = $event->broadcastWith();
        expect($payload['order_no'])->toStartWith('INV-');
        expect($payload['status'])->toBe('Completed');

        return true;
    });
});

it('broadcasts WorkOrderStatusChangedEvent on workshop.bay when work order is converted to invoice', function () {
    Event::fake([StockUpdatedEvent::class, OrderCompletedEvent::class, WorkOrderStatusChangedEvent::class]);

    $customer = Customer::first();
    $vehicle = Vehicle::first();
    $mechanic = Mechanic::first();
    $product = Product::where('is_labor', false)->first();

    $workOrder = WorkOrder::create([
        'job_card_no' => 'JC-REVERB-' . uniqid(),
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'assigned_mechanic_id' => $mechanic->id,
        'bay' => 'Bay 3',
        'complaint' => 'Transmission fluid flush',
        'labor_fee' => 20000.00,
        'parts_total' => $product->sell_price,
        'total_amount' => 20000.00 + $product->sell_price,
        'status' => 'In Progress',
    ]);

    WorkOrderItem::create([
        'work_order_id' => $workOrder->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'qty' => 1,
        'unit_price' => $product->sell_price,
        'cost_price' => $product->cost_price,
        'subtotal' => $product->sell_price,
    ]);

    $convertAction = app(ConvertWorkOrderToInvoiceAction::class);
    $convertAction->execute($workOrder->id, 'Cash', 1);

    Event::assertDispatched(WorkOrderStatusChangedEvent::class, function (WorkOrderStatusChangedEvent $event) use ($workOrder) {
        $channels = $event->broadcastOn();
        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('workshop.bay');
        expect($event->broadcastAs())->toBe('workshop.status.changed');

        $payload = $event->broadcastWith();
        expect($payload['job_card_no'])->toBe($workOrder->job_card_no);
        expect($payload['status'])->toBe('Completed / Invoiced');
        expect($payload['bay'])->toBe('Bay 3');

        return true;
    });
});
