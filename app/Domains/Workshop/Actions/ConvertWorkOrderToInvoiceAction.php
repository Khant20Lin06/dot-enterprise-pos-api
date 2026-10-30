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
     * automatically deducting inventory, calculating mechanic commissions,
     * and recording payment splits.
     */
    public function execute(
        int $workOrderId,
        string $paymentMethod = 'Cash',
        int $cashierId = 1,
        ?string $paymentRef = null
    ): array {
        return DB::transaction(function () use ($workOrderId, $paymentMethod, $cashierId, $paymentRef) {
            // 1. Lock Work Order
            $workOrder = WorkOrder::with('items.product')->where('id', $workOrderId)->lockForUpdate()->firstOrFail();

            if ($workOrder->status === 'Completed / Invoiced') {
                throw new \DomainException("Job Card '{$workOrder->job_card_no}' has already been invoiced.");
            }

            // 2. Automated Inventory Deduction for every fitted part (Resolving Audit Gap!)
            foreach ($workOrder->items as $item) {
                if (!$item->is_outsourced && $item->product_id) {
                    $this->deductStockAction->execute($item->product_id, $item->qty);
                }
            }

            // 3. Calculate Mechanic Commission
            $commission = $this->calculateCommissionAction->execute($workOrder);

            // 4. Handle Credit / Due Authorization if sold on account
            $totalAmount = (float) $workOrder->total_amount;
            $isCredit = ($paymentMethod === 'Credit');
            $paidAmount = $isCredit ? 0.00 : $totalAmount;
            $dueAmount = $isCredit ? $totalAmount : 0.00;

            if ($isCredit) {
                $customer = Customer::where('id', $workOrder->customer_id)->lockForUpdate()->firstOrFail();
                $newDue = (float) $customer->due_amount + $dueAmount;
                if ($customer->credit_limit > 0 && $newDue > (float) $customer->credit_limit) {
                    throw new CreditLimitExceededException(
                        "Credit limit exceeded. Limit: {$customer->credit_limit} Ks, Projected Due: {$newDue} Ks."
                    );
                }
                $customer->increment('due_amount', $dueAmount);
            }

            // 5. Generate Sales Order (Invoice)
            $orderNo = 'INV-' . now()->format('Ymd') . '-' . str_pad((string) (SalesOrder::count() + 1), 4, '0', STR_PAD_LEFT);

            $salesOrder = SalesOrder::create([
                'order_no' => $orderNo,
                'customer_id' => $workOrder->customer_id,
                'vehicle_id' => $workOrder->vehicle_id,
                'cashier_id' => $cashierId,
                'subtotal' => $workOrder->parts_total,
                'labor_total' => $workOrder->labor_fee,
                'discount_amount' => 0.00,
                'tax_amount' => 0.00,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
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

            // 7. If Labor fee exists, record labor item
            if ((float) $workOrder->labor_fee > 0) {
                $laborProduct = Product::where('is_labor', true)->first();
                if ($laborProduct) {
                    SalesOrderItem::create([
                        'sales_order_id' => $salesOrder->id,
                        'product_id' => $laborProduct->id,
                        'name' => 'Workshop Labor & Diagnostics',
                        'qty' => 1,
                        'unit_price' => $workOrder->labor_fee,
                        'cost_price' => 0.00,
                        'subtotal' => $workOrder->labor_fee,
                    ]);
                }
            }

            // 8. Record Payment Split
            PaymentSplit::create([
                'sales_order_id' => $salesOrder->id,
                'method' => $paymentMethod,
                'amount' => $totalAmount,
                'reference_no' => $paymentRef,
            ]);

            // 9. Update Work Order Status
            $workOrder->update([
                'status' => 'Completed / Invoiced',
                'sales_order_id' => $salesOrder->id,
            ]);

            $freshWorkOrder = $workOrder->fresh(['items', 'vehicle', 'customer', 'mechanic']);
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
