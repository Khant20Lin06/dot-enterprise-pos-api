<?php

namespace App\Domains\SalesAndPos\Actions;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Actions\ReplenishStockAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\SalesAndPos\Events\SalesReturnProcessedEvent;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SalesAndPos\Models\SalesOrderItem;
use App\Domains\SalesAndPos\Models\SalesReturn;
use App\Domains\SalesAndPos\Models\SalesReturnItem;
use App\Domains\SecurityAndAudit\Models\AuditLog;
use App\Services\AtomicSequenceService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessSaleReturnAction
{
    public function __construct(
        protected ReplenishStockAction $replenishStockAction,
        protected AtomicSequenceService $atomicSequenceService
    ) {}

    /**
     * Process an itemized sales return with concurrency control, inventory restocking,
     * loyalty points reversal, and credit debt adjustment.
     *
     * @param  array<string, mixed>  $data
     * @throws InvalidArgumentException
     */
    public function execute(array $data, int $cashierId): SalesReturn
    {
        return DB::transaction(function () use ($data, $cashierId) {
            $salesOrderId = (int) ($data['sales_order_id'] ?? 0);
            $itemsData = $data['items'] ?? [];
            $reason = trim((string) ($data['reason'] ?? 'Customer Return'));
            $refundMethod = $data['refund_method'] ?? 'Cash';
            $refundReference = $data['refund_reference'] ?? null;
            $notes = $data['notes'] ?? null;

            if (empty($itemsData)) {
                throw new InvalidArgumentException('Return must contain at least one line item.');
            }

            // 1. Lock and Verify Original Sales Order
            $salesOrder = SalesOrder::where('id', $salesOrderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($salesOrder->status === 'Voided') {
                throw new InvalidArgumentException("Cannot process return for voided order #{$salesOrder->order_no}.");
            }

            // 2. Lock Customer for Points and Credit Debt Adjustment
            $customer = Customer::where('id', $salesOrder->customer_id)
                ->lockForUpdate()
                ->first();

            $totalRefundAmount = 0.00;
            $returnItemsPayload = [];
            $allRestocked = true;

            // Sort return items ascending by product_id to prevent lock deadlocks
            usort($itemsData, function ($a, $b) {
                return ((int) ($a['product_id'] ?? 0)) <=> ((int) ($b['product_id'] ?? 0));
            });

            // 3. Validate Each Line Item Against Original Purchase
            foreach ($itemsData as $entry) {
                $orderItemId = (int) ($entry['sales_order_item_id'] ?? 0);
                $qty = (int) ($entry['qty'] ?? 1);
                $condition = $entry['item_condition'] ?? 'Sellable';

                if ($qty <= 0) {
                    throw new InvalidArgumentException('Returned quantity must be greater than zero.');
                }

                $orderItem = SalesOrderItem::where('id', $orderItemId)
                    ->where('sales_order_id', $salesOrder->id)
                    ->first();

                if (! $orderItem) {
                    throw new InvalidArgumentException("Order item ID #{$orderItemId} does not belong to order #{$salesOrder->order_no}.");
                }

                // Check previously returned quantity for this line item
                $previouslyReturnedQty = (int) SalesReturnItem::where('sales_order_item_id', $orderItem->id)
                    ->sum('qty');

                $maxReturnable = $orderItem->qty - $previouslyReturnedQty;

                if ($qty > $maxReturnable) {
                    throw new InvalidArgumentException(
                        "Cannot return {$qty} of '{$orderItem->name}'. Maximum returnable quantity is {$maxReturnable} (Purchased: {$orderItem->qty}, Previously Returned: {$previouslyReturnedQty})."
                    );
                }

                // Effective unit price based on subtotal (considers discounts)
                $effectiveUnitPrice = round((float) $orderItem->subtotal / $orderItem->qty, 2);
                $lineRefund = round($effectiveUnitPrice * $qty, 2);
                $totalRefundAmount += $lineRefund;

                $shouldRestock = ($condition === 'Sellable');
                if (! $shouldRestock) {
                    $allRestocked = false;
                }

                $returnItemsPayload[] = [
                    'sales_order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'product_option_id' => $orderItem->product_option_id,
                    'qty' => $qty,
                    'unit_price' => $effectiveUnitPrice,
                    'refund_subtotal' => $lineRefund,
                    'item_condition' => $condition,
                    'restocked' => $shouldRestock,
                ];
            }

            // 4. Physical Inventory Restocking / Quarantine
            foreach ($returnItemsPayload as $rItem) {
                $product = Product::where('id', $rItem['product_id'])->lockForUpdate()->firstOrFail();

                if ($rItem['restocked']) {
                    // Restock sellable inventory
                    $this->replenishStockAction->execute(
                        $rItem['product_id'],
                        $rItem['qty'],
                        $rItem['product_option_id']
                    );
                } else {
                    // Quarantine damaged/scrap goods: create a stock adjustment record
                    StockAdjustment::create([
                        'product_id' => $rItem['product_id'],
                        'type' => 'Damage / Scrap',
                        'prev_stock' => $product->stock,
                        'adjusted_qty' => 0, // Unusable scrap; not added to sellable stock
                        'new_stock' => $product->stock,
                        'reason' => "Sales Return damaged item quarantine (Qty: {$rItem['qty']}, Condition: {$rItem['item_condition']})",
                        'staff_id' => $cashierId,
                    ]);
                }
            }

            // 5. Proportional Loyalty Points Reversal
            $pointsToDeduct = 0;
            if ($customer && $salesOrder->points_earned > 0 && $salesOrder->total_amount > 0) {
                $ratio = min(1.0, $totalRefundAmount / (float) $salesOrder->total_amount);
                $pointsToDeduct = (int) round($salesOrder->points_earned * $ratio);
                $pointsToDeduct = min($pointsToDeduct, $customer->loyalty_points); // Floor guard

                if ($pointsToDeduct > 0) {
                    $customer->decrement('loyalty_points', $pointsToDeduct);
                }
            }

            // 6. Credit Debt & Balance Adjustment
            if ($customer && in_array($refundMethod, ['Credit Note', 'Credit Adjusted'])) {
                $currentDue = (float) $customer->current_due;
                if ($currentDue > 0) {
                    $debtReduction = min($totalRefundAmount, $currentDue);
                    $customer->decrement('due_amount', $debtReduction);
                }
            }

            // 7. Atomic Concurrency-Safe Return Sequence
            $returnNo = $this->atomicSequenceService->getNextSequence('RET');
            $status = $allRestocked ? 'Restocked' : 'Completed';

            // 8. Create Master Sales Return Record
            $activeSession = \App\Domains\SalesAndPos\Models\PosSession::where('cashier_id', $cashierId)->where('status', 'open')->first();
            $sessionId = $data['pos_session_id'] ?? $activeSession?->id;

            $salesReturn = SalesReturn::create([
                'return_no' => $returnNo,
                'sales_order_id' => $salesOrder->id,
                'customer_id' => $salesOrder->customer_id,
                'cashier_id' => $cashierId,
                'pos_session_id' => $sessionId,
                'total_refund_amount' => $totalRefundAmount,
                'refund_method' => $refundMethod,
                'refund_reference' => $refundReference,
                'reason' => $reason,
                'status' => $status,
                'notes' => $notes,
                'points_deducted' => $pointsToDeduct,
            ]);

            // 9. Create Return Items
            foreach ($returnItemsPayload as $itemData) {
                $itemData['sales_return_id'] = $salesReturn->id;
                SalesReturnItem::create($itemData);
            }

            // 10. Check if entire order is fully returned
            $totalOrderedQty = (int) $salesOrder->items()->sum('qty');
            $totalReturnedQty = (int) SalesReturnItem::whereIn('sales_order_item_id', $salesOrder->items()->pluck('id'))
                ->sum('qty');

            if ($totalReturnedQty >= $totalOrderedQty) {
                $salesOrder->update(['status' => 'Returned']);
            }

            // 11. Record Security & Financial Audit Trail
            AuditLog::create([
                'staff_id' => $cashierId,
                'module' => 'sales_and_pos',
                'action' => 'sales_return_processed',
                'severity' => 'info',
                'resource_type' => 'sales_returns',
                'resource_id' => (string) $salesReturn->id,
                'description' => "Sales Return {$returnNo} processed for Order #{$salesOrder->order_no}. Refund: {$totalRefundAmount} Ks ({$refundMethod}), Points Deducted: {$pointsToDeduct}",
                'diffs' => [
                    'return_no' => $returnNo,
                    'sales_order_no' => $salesOrder->order_no,
                    'total_refund_amount' => $totalRefundAmount,
                    'refund_method' => $refundMethod,
                    'points_deducted' => $pointsToDeduct,
                ],
                'created_at' => now(),
            ]);

            // 12. Broadcast Real-Time Event
            event(new SalesReturnProcessedEvent($salesReturn->load(['items.product', 'salesOrder', 'customer', 'cashier'])));

            return $salesReturn->fresh(['items.product', 'salesOrder', 'customer', 'cashier']);
        });
    }
}
