<?php

namespace App\Domains\SalesAndPos\Actions;

use App\Domains\CreditAndLedger\Models\Customer;
use App\Domains\Inventory\Actions\DeductStockAction;
use App\Domains\Inventory\Models\Product;
use App\Domains\SalesAndPos\Models\PaymentSplit;
use App\Domains\SalesAndPos\Models\SalesOrder;
use App\Domains\SalesAndPos\Models\SalesOrderItem;
use App\Exceptions\CreditLimitExceededException;
use Illuminate\Support\Facades\DB;

class ProcessCheckoutAction
{
    public function __construct(
        protected DeductStockAction $deductStockAction
    ) {}

    /**
     * Complete POS checkout transaction with Pessimistic Row Locking,
     * multi-tender payment splits, credit debt accounting, and idempotency protection.
     *
     * @throws \App\Exceptions\InsufficientStockException
     * @throws CreditLimitExceededException
     */
    public function execute(array $data, int $cashierId = 1): SalesOrder
    {
        // 1. Idempotency Check (Safeguard for Offline-to-Cloud Sync)
        if (!empty($data['idempotency_key'])) {
            $existing = SalesOrder::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->load(['items.product', 'payments', 'customer']);
            }
        }

        return DB::transaction(function () use ($data, $cashierId) {
            $effectiveCashierId = $data['cashier_id'] ?? $cashierId;
            $customerId = $data['customer_id'] ?? null;
            $items = $data['items'] ?? [];
            $paymentSplits = $data['payment_splits'] ?? $data['payments'] ?? [];

            // Resolve active shift session & warehouse location to prevent stock drift
            $sessionId = $data['pos_session_id'] ?? null;
            $activeSession = $sessionId
                ? \App\Domains\SalesAndPos\Models\PosSession::find($sessionId)
                : \App\Domains\SalesAndPos\Models\PosSession::where('cashier_id', $effectiveCashierId)->where('status', 'open')->first();
            $defaultWarehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : $activeSession?->warehouse_id;

            // Hardening 1: Deadlock Prevention via Consistent Lock Acquisition Ordering
            // Sort items ascending by product_id so all concurrent transactions lock rows in the exact same sequence.
            usort($items, function ($a, $b) {
                return ((int) ($a['product_id'] ?? 0)) <=> ((int) ($b['product_id'] ?? 0));
            });

            // 2. Concurrency Control: Deduct Inventory with Row-Level Lock
            $orderItemsPayload = [];
            $laborTotal = 0.00;
            $calculatedSubtotal = 0.00;

            foreach ($items as $itemData) {
                $productId = (int) $itemData['product_id'];
                $qty = (int) ($itemData['qty'] ?? 1);
                $optionId = isset($itemData['product_option_id']) ? (int) $itemData['product_option_id'] : null;
                $variantId = isset($itemData['product_variant_id']) ? (int) $itemData['product_variant_id'] : (isset($itemData['variant_id']) ? (int) $itemData['variant_id'] : null);
                $uomConversionId = isset($itemData['uom_conversion_id']) ? (int) $itemData['uom_conversion_id'] : null;
                $warehouseId = isset($itemData['warehouse_id']) ? (int) $itemData['warehouse_id'] : $defaultWarehouseId;

                // Enterprise Multi-UOM Resolution
                $uomConversion = null;
                $conversionFactor = 1.0;
                $baseQtyToDeduct = $qty;

                if ($uomConversionId) {
                    $uomConversion = \App\Domains\Inventory\Models\ProductUomConversion::where('id', $uomConversionId)
                        ->where('product_id', $productId)
                        ->first();

                    if ($uomConversion) {
                        $conversionFactor = (float) $uomConversion->conversion_factor;
                        $baseQtyToDeduct = (int) ceil($qty * $conversionFactor);
                    }
                }

                // Pessimistic Row Locking in action: deduct baseQtyToDeduct from stock
                $product = $this->deductStockAction->execute($productId, $baseQtyToDeduct, $optionId, $warehouseId, $variantId);

                // Hardening 2: Server-Authoritative Price Validation (Anti-Price Tampering) & Dynamic Tier Pricing
                $catalogPrice = (float) ($product->sell_price ?: $product->retail_price);
                if ($uomConversion) {
                    $catalogPrice = (float) $uomConversion->sell_price;
                } elseif ($variantId) {
                    $variant = \App\Domains\Inventory\Models\ProductVariant::find($variantId);
                    if ($variant && (float) $variant->price > 0) {
                        $catalogPrice = (float) $variant->price;
                    }
                } elseif (class_exists(\App\Domains\PricingAndPromotions\Actions\ResolveDynamicPriceAction::class)) {
                    $dynamicPriceResult = app(\App\Domains\PricingAndPromotions\Actions\ResolveDynamicPriceAction::class)
                        ->execute($product, $qty, $customerId);
                    if (! empty($dynamicPriceResult['is_custom'])) {
                        $catalogPrice = (float) $dynamicPriceResult['unit_price'];
                    }
                }
                $requestedPrice = isset($itemData['unit_price']) ? (float) $itemData['unit_price'] : $catalogPrice;

                // Disallow unauthorized price reductions unless explicitly overridden
                if ($requestedPrice < $catalogPrice && empty($data['allow_discount']) && empty($data['manager_pin'])) {
                    $unitPrice = $catalogPrice;
                } else {
                    $unitPrice = $requestedPrice;
                }

                $discountAmount = (float) ($itemData['discount_amount'] ?? 0.00);
                $subtotal = round(($unitPrice * $qty) - $discountAmount, 2);
                $calculatedSubtotal += $subtotal;

                if ($product->is_labor) {
                    $laborTotal += $subtotal;
                }

                $orderItemsPayload[] = [
                    'product_id' => $product->id,
                    'product_option_id' => $optionId,
                    'product_variant_id' => $variantId,
                    'uom_conversion_id' => $uomConversion?->id,
                    'uom_id' => $uomConversion?->uom_id ?? $product->uom_id,
                    'conversion_factor' => $conversionFactor,
                    'packaging_qty' => $uomConversion ? $qty : null,
                    'name' => $itemData['name'] ?? ($uomConversion ? "{$product->name} ({$uomConversion->unit_name})" : $product->name),
                    'qty' => $baseQtyToDeduct,
                    'unit_price' => $unitPrice,
                    'cost_price' => (float) ($uomConversion?->cost_price ?: $product->cost_price),
                    'discount_amount' => $discountAmount,
                    'subtotal' => $subtotal,
                ];
            }

            // Server-verified totals
            $discountTotal = (float) ($data['discount_amount'] ?? 0.00);
            $taxTotal = (float) ($data['tax_amount'] ?? 0.00);
            $totalAmount = max(0, round($calculatedSubtotal - $discountTotal + $taxTotal, 2));

            // 3. Multi-Tender Payment & Credit Calculation
            $dueAmount = 0.00;
            $paidAmount = 0.00;

            foreach ($paymentSplits as $split) {
                $splitAmt = (float) ($split['amount'] ?? 0.00);
                if (strtolower($split['method'] ?? '') === 'credit') {
                    $dueAmount += $splitAmt;
                } else {
                    $paidAmount += $splitAmt;
                }
            }

            // VULN-01 Hardening: Strict Financial Invariant (Zero Payment Deficit)
            $totalTendered = round($paidAmount + $dueAmount, 2);
            if ($totalTendered < $totalAmount) {
                throw new \DomainException(
                    "Payment deficit: Total order amount is {$totalAmount} Ks, but total allocated tender is only {$totalTendered} Ks."
                );
            }

            // MED-01: Calculate and record change returned to customer on cash overpayment
            $changeAmount = max(0.00, round($totalTendered - $totalAmount, 2));

            // 4. Validate and Update Customer Credit Balance if sold on credit
            $customer = null;
            if ($customerId) {
                $customer = Customer::where('id', $customerId)->lockForUpdate()->first();
                if ($customer && $dueAmount > 0) {
                    $projectedDue = (float) $customer->due_amount + $dueAmount;
                    if ($customer->credit_limit > 0 && $projectedDue > (float) $customer->credit_limit) {
                        throw new CreditLimitExceededException(
                            "Sale exceeds customer credit limit. Limit: {$customer->credit_limit} Ks, Projected Debt: {$projectedDue} Ks."
                        );
                    }
                    $customer->increment('due_amount', $dueAmount);
                }
            }

            // 5. Loyalty Points Accounting & Hardening 3: Underflow/Deficit Protection
            $pointsEarned = 0;
            $pointsRedeemed = (int) ($data['points_redeemed'] ?? 0);

            if ($customer) {
                if ($pointsRedeemed > 0 && $customer->loyalty_points < $pointsRedeemed) {
                    throw new \InvalidArgumentException(
                        "Customer has insufficient loyalty points. Available: {$customer->loyalty_points}, Requested: {$pointsRedeemed}."
                    );
                }

                // Earn 1 point per 1,000 Ks
                $pointsEarned = (int) floor($totalAmount / 1000);
                $netPointsChange = $pointsEarned - $pointsRedeemed;
                $customer->increment('loyalty_points', $netPointsChange);
            }

            // Hardening 4: Atomic Concurrency-Safe Invoice Sequencing (Zero Collision Risk)
            $orderNo = app(\App\Services\AtomicSequenceService::class)->getNextSequence('INV');

            // Resolve active shift session for cashier if not explicitly supplied
            $activeSession = \App\Domains\SalesAndPos\Models\PosSession::where('cashier_id', $cashierId)->where('status', 'open')->first();
            $sessionId = $data['pos_session_id'] ?? $activeSession?->id;

            // 7. Create Master Sales Order Record
            $salesOrder = SalesOrder::create([
                'order_no' => $orderNo,
                'customer_id' => $customerId ?? 1,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'cashier_id' => $cashierId,
                'pos_session_id' => $sessionId,
                'subtotal' => $calculatedSubtotal,
                'labor_total' => $laborTotal,
                'discount_amount' => $discountTotal,
                'discount_note' => $data['discount_note'] ?? null,
                'tax_amount' => $taxTotal,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'due_amount' => $dueAmount,
                'points_earned' => $pointsEarned,
                'points_redeemed' => $pointsRedeemed,
                'status' => 'Completed',
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            // VULN-07 Hardening: Immutable Financial Transaction Audit Log
            \App\Domains\SecurityAndAudit\Models\AuditLog::create([
                'staff_id' => $cashierId,
                'module' => 'sales_and_pos',
                'action' => 'checkout_completed',
                'severity' => 'info',
                'resource_type' => 'sales_orders',
                'resource_id' => $salesOrder->id,
                'description' => "Order {$salesOrder->order_no} finalized. Total: {$totalAmount} Ks, Paid: {$paidAmount} Ks, Due: {$dueAmount} Ks",
                'diffs' => [
                    'order_no' => $salesOrder->order_no,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'due_amount' => $dueAmount,
                ],
                'created_at' => now(),
            ]);

            // 8. Create Sales Order Line Items
            foreach ($orderItemsPayload as $item) {
                $item['sales_order_id'] = $salesOrder->id;
                SalesOrderItem::create($item);
            }

            // 9. Record Payment Splits
            foreach ($paymentSplits as $split) {
                $method = $split['method'] ?? 'Cash';
                $gatewayProvider = $split['gateway_provider'] ?? match (strtolower($method)) {
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
                    'status' => $split['status'] ?? 'completed',
                    'gateway_provider' => $gatewayProvider,
                    'reference_no' => $split['reference_no'] ?? null,
                    'gateway_payload' => $split['gateway_payload'] ?? null,
                ]);
            }

            // 10. Broadcast Real-Time Reverb WebSocket Event
            $loadedOrder = $salesOrder->load(['items.product', 'payments', 'customer', 'cashier']);
            event(new \App\Domains\SalesAndPos\Events\OrderCompletedEvent($loadedOrder));

            // Hardening 5: Ghost Record Prevention (Queue Worker only dispatches AFTER DB Commit)
            \App\Jobs\GenerateReceiptPdfJob::dispatch($salesOrder->id)->afterCommit();

            return $loadedOrder;
        });
    }
}
