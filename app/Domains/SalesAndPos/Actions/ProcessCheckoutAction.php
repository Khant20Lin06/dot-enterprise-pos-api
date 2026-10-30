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
            $customerId = $data['customer_id'] ?? null;
            $items = $data['items'] ?? [];
            $paymentSplits = $data['payment_splits'] ?? [];
            $totalAmount = (float) ($data['total_amount'] ?? 0.00);

            // 2. Concurrency Control: Deduct Inventory with Row-Level Lock
            $orderItemsPayload = [];
            $laborTotal = 0.00;

            foreach ($items as $itemData) {
                $productId = (int) $itemData['product_id'];
                $qty = (int) ($itemData['qty'] ?? 1);
                $optionId = isset($itemData['product_option_id']) ? (int) $itemData['product_option_id'] : null;

                // Pessimistic Row Locking in action
                $product = $this->deductStockAction->execute($productId, $qty, $optionId);

                $unitPrice = (float) ($itemData['unit_price'] ?? $product->sell_price);
                $discountAmount = (float) ($itemData['discount_amount'] ?? 0.00);
                $subtotal = round(($unitPrice * $qty) - $discountAmount, 2);

                if ($product->is_labor) {
                    $laborTotal += $subtotal;
                }

                $orderItemsPayload[] = [
                    'product_id' => $product->id,
                    'product_option_id' => $optionId,
                    'name' => $itemData['name'] ?? $product->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'cost_price' => (float) $product->cost_price,
                    'discount_amount' => $discountAmount,
                    'subtotal' => $subtotal,
                ];
            }

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

            // 5. Loyalty Points Accounting
            $pointsEarned = 0;
            $pointsRedeemed = (int) ($data['points_redeemed'] ?? 0);

            if ($customer) {
                // Earn 1 point per 1,000 Ks
                $pointsEarned = (int) floor($totalAmount / 1000);
                $netPointsChange = $pointsEarned - $pointsRedeemed;
                $customer->increment('loyalty_points', $netPointsChange);
            }

            // 6. Generate Sequential Invoice Number
            $orderNo = 'INV-' . now()->format('Ymd') . '-' . str_pad((string) (SalesOrder::count() + 1), 4, '0', STR_PAD_LEFT);

            // 7. Create Master Sales Order Record
            $salesOrder = SalesOrder::create([
                'order_no' => $orderNo,
                'customer_id' => $customerId ?? 1,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'cashier_id' => $cashierId,
                'subtotal' => (float) ($data['subtotal'] ?? $totalAmount),
                'labor_total' => $laborTotal,
                'discount_amount' => (float) ($data['discount_amount'] ?? 0.00),
                'discount_note' => $data['discount_note'] ?? null,
                'tax_amount' => (float) ($data['tax_amount'] ?? 0.00),
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'points_earned' => $pointsEarned,
                'points_redeemed' => $pointsRedeemed,
                'status' => 'Completed',
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            // 8. Create Sales Order Line Items
            foreach ($orderItemsPayload as $item) {
                $item['sales_order_id'] = $salesOrder->id;
                SalesOrderItem::create($item);
            }

            // 9. Record Payment Splits
            foreach ($paymentSplits as $split) {
                PaymentSplit::create([
                    'sales_order_id' => $salesOrder->id,
                    'method' => $split['method'] ?? 'Cash',
                    'amount' => (float) ($split['amount'] ?? 0.00),
                    'reference_no' => $split['reference_no'] ?? null,
                ]);
            }

            // 10. Broadcast Real-Time Reverb WebSocket Event
            $loadedOrder = $salesOrder->load(['items.product', 'payments', 'customer', 'cashier']);
            event(new \App\Domains\SalesAndPos\Events\OrderCompletedEvent($loadedOrder));

            // 11. Dispatch Queued Background Thermal Receipt Generation Job (Horizon/Redis)
            \App\Jobs\GenerateReceiptPdfJob::dispatch($salesOrder->id);

            return $loadedOrder;
        });
    }
}
