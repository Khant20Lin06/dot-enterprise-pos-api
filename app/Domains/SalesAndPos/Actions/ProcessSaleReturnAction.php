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
        // 1. Idempotency Check (Prevent duplicate refund charges and restock loops)
        if (! empty($data['idempotency_key'])) {
            $existing = SalesReturn::where('idempotency_key', $data['idempotency_key'])
                ->with(['items.product', 'salesOrder', 'customer', 'cashier'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

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

                // Packaging UOM Unit Conversion Alignment
                $isPackaging = (! empty($entry['is_packaging']) || isset($entry['packaging_qty'])) && $orderItem->uom_conversion_id;
                $conversionFactor = (float) ($orderItem->conversion_factor ?: 1.0);
                $effectiveQty = $isPackaging
                    ? (int) ceil((isset($entry['packaging_qty']) ? (int) $entry['packaging_qty'] : $qty) * $conversionFactor)
                    : $qty;

                if ($effectiveQty <= 0) {
                    throw new InvalidArgumentException('Returned quantity must be greater than zero.');
                }

                // Check previously returned quantity for this line item
                $previouslyReturnedQty = (int) SalesReturnItem::where('sales_order_item_id', $orderItem->id)
                    ->sum('qty');

                $maxReturnable = $orderItem->qty - $previouslyReturnedQty;

                if ($effectiveQty > $maxReturnable) {
                    throw new InvalidArgumentException(
                        "Cannot return {$effectiveQty} base units of '{$orderItem->name}'. Maximum returnable quantity is {$maxReturnable} (Purchased: {$orderItem->qty}, Previously Returned: {$previouslyReturnedQty})."
                    );
                }

                // Effective unit price based on subtotal (considers discounts)
                $effectiveUnitPrice = round((float) $orderItem->subtotal / $orderItem->qty, 2);
                $lineRefund = round($effectiveUnitPrice * $effectiveQty, 2);
                $totalRefundAmount += $lineRefund;

                $shouldRestock = ($condition === 'Sellable');
                if (! $shouldRestock) {
                    $allRestocked = false;
                }

                $returnItemsPayload[] = [
                    'sales_order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'product_option_id' => $orderItem->product_option_id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'qty' => $effectiveQty,
                    'unit_price' => $effectiveUnitPrice,
                    'refund_subtotal' => $lineRefund,
                    'item_condition' => $condition,
                    'restocked' => $shouldRestock,
                ];
            }

            // Resolve target warehouse for physical restocking
            $activeSession = \App\Domains\SalesAndPos\Models\PosSession::where('cashier_id', $cashierId)->where('status', 'open')->first();
            $targetWarehouseId = $data['warehouse_id'] ?? $activeSession?->warehouse_id ?? null;

            // 4. Physical Inventory Restocking / Quarantine
            foreach ($returnItemsPayload as $rItem) {
                $product = Product::where('id', $rItem['product_id'])->lockForUpdate()->firstOrFail();

                if ($rItem['restocked']) {
                    // Restock sellable inventory across Product Master, Variant, and Warehouse Location
                    $this->replenishStockAction->execute(
                        productId: $rItem['product_id'],
                        qty: $rItem['qty'],
                        productOptionId: $rItem['product_option_id'],
                        warehouseId: $targetWarehouseId,
                        productVariantId: $rItem['product_variant_id']
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

            // 5. Proportional Loyalty Points Reversal & Arbitrage Floor Protection (HIGH-02)
            $pointsToDeduct = 0;
            if ($customer && $salesOrder->points_earned > 0 && $salesOrder->total_amount > 0) {
                $ratio = min(1.0, $totalRefundAmount / (float) $salesOrder->total_amount);
                $theoreticalPointsToDeduct = (int) round($salesOrder->points_earned * $ratio);

                if ($customer->loyalty_points < $theoreticalPointsToDeduct) {
                    // Exploit mitigation: Customer consumed points. Claw back equivalent cash from refund.
                    $pointsDeficit = $theoreticalPointsToDeduct - $customer->loyalty_points;
                    $pointsToDeduct = $customer->loyalty_points;

                    if ($customer->loyalty_points > 0) {
                        $customer->decrement('loyalty_points', $customer->loyalty_points);
                    }

                    // 1 point per 1,000 Ks
                    $pointValue = 1000.00;
                    $cashDeduction = round($pointsDeficit * $pointValue, 2);
                    $totalRefundAmount = max(0.00, round($totalRefundAmount - $cashDeduction, 2));
                } else {
                    $pointsToDeduct = $theoreticalPointsToDeduct;
                    if ($pointsToDeduct > 0) {
                        $customer->decrement('loyalty_points', $pointsToDeduct);
                    }
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
                'idempotency_key' => $data['idempotency_key'] ?? null,
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
            $salesReturn->load(['items.product', 'salesOrder', 'customer', 'cashier']);
            event(new SalesReturnProcessedEvent($salesReturn));

            return $salesReturn;
        });
    }
}
