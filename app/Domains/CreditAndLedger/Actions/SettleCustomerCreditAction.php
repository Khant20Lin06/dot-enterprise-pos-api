<?php

namespace App\Domains\CreditAndLedger\Actions;

use App\Domains\CreditAndLedger\Models\CreditSettlement;
use App\Domains\CreditAndLedger\Models\Customer;
use Illuminate\Support\Facades\DB;

class SettleCustomerCreditAction
{
    /**
     * Settle outstanding customer credit/debt with transactional locking.
     */
    public function execute(
        int $customerId,
        float $amount,
        string $paymentMethod = 'Cash',
        ?string $note = null,
        ?int $cashierId = null,
        ?int $posSessionId = null
    ): CreditSettlement {
        // VULN-06 Hardening: Invariant assertion (Prevent negative debt inflation)
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Settlement payment amount must be strictly greater than zero.");
        }

        return DB::transaction(function () use ($customerId, $amount, $paymentMethod, $note, $cashierId, $posSessionId) {
            // 1. Lock customer row to prevent concurrent balance race condition
            $customer = Customer::where('id', $customerId)->lockForUpdate()->firstOrFail();

            // 2. Compute settlement amount (cannot pay more than current due unless credited)
            $settleAmount = min($amount, (float) $customer->due_amount);

            // 3. Deduct Due Balance
            $customer->decrement('due_amount', $settleAmount);

            // 4. Generate Atomic Sequential Receipt Number (Concurrency-Safe)
            $receiptNo = app(\App\Services\AtomicSequenceService::class)->getNextSequence('REC');

            // Resolve active cashier shift session if not explicitly provided
            $resolvedSessionId = $posSessionId ?? ($cashierId ? \App\Domains\SalesAndPos\Models\PosSession::where('cashier_id', $cashierId)->where('status', 'open')->first()?->id : null);

            // 5. Create Settlement Record
            return CreditSettlement::create([
                'receipt_no' => $receiptNo,
                'customer_id' => $customer->id,
                'amount' => $settleAmount,
                'payment_method' => $paymentMethod,
                'note' => $note,
                'cashier_id' => $cashierId ?? 1,
                'pos_session_id' => $resolvedSessionId,
            ]);
        });
    }
}
