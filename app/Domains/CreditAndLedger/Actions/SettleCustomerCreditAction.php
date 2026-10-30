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
        ?int $cashierId = null
    ): CreditSettlement {
        return DB::transaction(function () use ($customerId, $amount, $paymentMethod, $note, $cashierId) {
            // 1. Lock customer row to prevent concurrent balance race condition
            $customer = Customer::where('id', $customerId)->lockForUpdate()->firstOrFail();

            // 2. Compute settlement amount (cannot pay more than current due unless credited)
            $settleAmount = min($amount, (float) $customer->due_amount);

            // 3. Deduct Due Balance
            $customer->decrement('due_amount', $settleAmount);

            // 4. Generate Atomic Sequential Receipt Number (Concurrency-Safe)
            $receiptNo = app(\App\Services\AtomicSequenceService::class)->getNextSequence('REC');

            // 5. Create Settlement Record
            return CreditSettlement::create([
                'receipt_no' => $receiptNo,
                'customer_id' => $customer->id,
                'amount' => $settleAmount,
                'payment_method' => $paymentMethod,
                'note' => $note,
                'cashier_id' => $cashierId ?? 1,
            ]);
        });
    }
}
