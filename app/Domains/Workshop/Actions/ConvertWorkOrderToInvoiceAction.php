<?php

namespace App\Domains\Workshop\Actions;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Actions\DeductStockAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\SalesAndPos\Models\PaymentSplit;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SalesAndPos\Models\SalesOrderItem;
use App\Domains\Workshop\Models\WorkOrder;
use App\Exceptions\CreditLimitExceededException;
use Illuminate\Support\Facades\DB;

class ConvertWorkOrderToInvoiceAction
{
    public function __construct(
        protected DeductStockAction $deductStockAction,
        protected CalculateMechanicCommissionAction $calculateCommissionAction
    ) {}

    /**
     * Convert Work Order / Job Card into an official Invoice (SalesOrder),
     * automatically deducting inventory with warehouse resolution, calculating mechanic commissions,
     * and supporting multi-tender payment splits.
     *
     * @param  array<array<string, mixed>>|string  $paymentMethod  Single method or array of multi-tender splits
     */
    public function execute(
        int $workOrderId,
        array|string $paymentMethod = 'Cash',
        int $cashierId = 1,
        ?string $paymentRef = null,
        ?int $warehouseId = null
    ): array {
        return DB::transaction(function () use ($workOrderId, $paymentMethod, $cashierId, $paymentRef, $warehouseId) {
            // 1. Lock Work Order
            $workOrder = WorkOrder::with('items.product')->where('id', $workOrderId)->lockForUpdate()->firstOrFail();

            if ($workOrder->status === 'Completed / Invoiced') {
                throw new \DomainException("Job Card '{$workOrder->job_card_no}' has already been invoiced.");
            }

            if ($workOrder->status === 'Cancelled') {
                throw new \DomainException("Cannot invoice cancelled Job Card '{$workOrder->job_card_no}'.");
            }

            // Resolve cashier active session & warehouse
            $activeSession = \App\Domains\SalesAndPos\Models\PosSession::where('cashier_id', $cashierId)
                ->where('status', 'open')
                ->first();
            $targetWarehouseId = $warehouseId ?? $activeSession?->warehouse_id;

            // 2. Automated Inventory Deduction for every fitted part (Deadlock-safe sorted locks)
            $sortedItems = $workOrder->items->sortBy('product_id');
            foreach ($sortedItems as $item) {
                if (! $item->is_outsourced && $item->product_id) {
                    $conversionFactor = (float) ($item->conversion_factor ?: 1.0);
                    $baseQtyToDeduct = (int) ceil($item->qty * $conversionFactor);

                    $this->deductStockAction->execute(
                        productId: $item->product_id,
                        qty: $baseQtyToDeduct,
                        productOptionId: null,
                        warehouseId: $targetWarehouseId,
                        productVariantId: null
                    );
                }
            }

            // 3. Calculate Mechanic Commission
            $commission = $this->calculateCommissionAction->execute($workOrder);

            // 4. Multi-Tender Payment Splits Normalization
            $totalAmount = (float) $workOrder->total_amount;
            $paymentSplits = [];

            if (is_array($paymentMethod)) {
                $paymentSplits = $paymentMethod;
            } else {
                $paymentSplits = [
                    [
                        'method' => $paymentMethod,
                        'amount' => $totalAmount,
                        'reference_no' => $paymentRef,
                    ],
                ];
            }

            $dueAmount = 0.00;
            $paidAmount = 0.00;

            foreach ($paymentSplits as $split) {
                $amt = (float) ($split['amount'] ?? 0.00);
                if (strtolower($split['method'] ?? '') === 'credit') {
                    $dueAmount += $amt;
                } else {
                    $paidAmount += $amt;
                }
            }

            $totalTendered = round($paidAmount + $dueAmount, 2);
            if ($totalTendered < $totalAmount) {
                throw new \DomainException(
                    "Payment deficit: Job Card total is {$totalAmount} Ks, but total allocated tender is only {$totalTendered} Ks."
                );
            }

            $changeAmount = max(0.00, round($totalTendered - $totalAmount, 2));

            // Customer Credit Authorization
            if ($dueAmount > 0) {
                $customer = Customer::where('id', $workOrder->customer_id)->lockForUpdate()->firstOrFail();
                $newDue = (float) $customer->due_amount + $dueAmount;
                if ($customer->credit_limit > 0 && $newDue > (float) $customer->credit_limit) {
                    throw new CreditLimitExceededException(
                        "Credit limit exceeded. Limit: {$customer->credit_limit} Ks, Projected Due: {$newDue} Ks."
                    );
                }
                $customer->increment('due_amount', $dueAmount);
            }

            // 5. Generate Sales Order with Atomic Concurrency-Safe Sequencing
            $orderNo = app(\App\Services\AtomicSequenceService::class)->getNextSequence('INV');

            $salesOrder = SalesOrder::create([
                'order_no' => $orderNo,
                'customer_id' => $workOrder->customer_id,
                'vehicle_id' => $workOrder->vehicle_id,
                'cashier_id' => $cashierId,
                'pos_session_id' => $activeSession?->id,
                'subtotal' => $workOrder->parts_total,
                'labor_total' => $workOrder->labor_fee,
                'discount_amount' => 0.00,
                'tax_amount' => 0.00,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'due_amount' => $dueAmount,
                'status' => 'Completed',
            ]);

            // 6. Create Sales Order Items
            foreach ($workOrder->items as $item) {
                SalesOrderItem::create([
                    'sales_order_id' => $salesOrder->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'qty' => $item->qty,
                    'unit_price' => $item->unit_price,
                    'cost_price' => $item->cost_price,
                    'discount_amount' => 0.00,
                    'subtotal' => $item->subtotal,
                ]);
            }

            // 7. If Labor fee exists, record itemized labor service line item
            if ((float) $workOrder->labor_fee > 0) {
                $laborProduct = Product::where('is_labor', true)->first();
                if (! $laborProduct) {
                    $category = \App\Domains\Inventory\Models\Category::first();
                    $uom = \App\Domains\Inventory\Models\UnitOfMeasure::first();
                    $laborProduct = Product::create([
                        'category_id' => $category?->id ?? 1,
                        'uom_id' => $uom?->id ?? 1,
                        'name' => 'Workshop Labor & Diagnostics',
                        'barcode' => 'LABOR-SERVICE-SYS',
                        'sell_price' => 0.00,
                        'cost_price' => 0.00,
                        'stock' => 999999,
                        'is_labor' => true,
                    ]);
                }

                SalesOrderItem::create([
                    'sales_order_id' => $salesOrder->id,
                    'product_id' => $laborProduct->id,
                    'name' => 'Workshop Labor & Diagnostics',
                    'qty' => 1,
                    'unit_price' => $workOrder->labor_fee,
                    'cost_price' => 0.00,
                    'discount_amount' => 0.00,
                    'subtotal' => $workOrder->labor_fee,
                ]);
            }

            // 8. Record Multi-Tender Payment Splits
            foreach ($paymentSplits as $split) {
                $method = $split['method'] ?? 'Cash';
                $gatewayProvider = match (strtolower($method)) {
                    'kpay', 'kbzpay' => 'kbzpay',
                    'wave', 'wavepay' => 'wavepay',
                    'card', 'credit card' => 'card',
                    'credit', 'due' => 'credit',
                    default => 'cash',
                };

                PaymentSplit::create([
                    'sales_order_id' => $salesOrder->id,
                    'method' => $method,
                    'amount' => (float) ($split['amount'] ?? 0.00),
                    'status' => 'completed',
                    'gateway_provider' => $gatewayProvider,
                    'reference_no' => $split['reference_no'] ?? null,
                ]);
            }

            // 9. Update Work Order Status
            $workOrder->update([
                'status' => 'Completed / Invoiced',
                'sales_order_id' => $salesOrder->id,
            ]);

            $freshWorkOrder = $workOrder->fresh(['items', 'vehicle', 'customer', 'primaryMechanic']);
            $freshSalesOrder = $salesOrder->fresh(['items', 'payments', 'customer', 'cashier']);

            // 10. Broadcast Real-Time Reverb WebSocket Events
            event(new \App\Domains\Workshop\Events\WorkOrderStatusChangedEvent($freshWorkOrder, 'In Progress'));
            event(new \App\Domains\SalesAndPos\Events\OrderCompletedEvent($freshSalesOrder));

            return [
                'work_order' => $freshWorkOrder,
                'sales_order' => $freshSalesOrder,
                'commission' => $commission,
            ];
        });
    }
}
