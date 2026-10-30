<?php

namespace App\Domains\SalesAndPos\Actions;

use App\Domains\SalesAndPos\Gateways\PaymentGatewayManager;
use App\Domains\SalesAndPos\Models\PaymentTransaction;
use App\Services\AtomicSequenceService;
use DomainException;

class CreatePaymentTransactionAction
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
        protected AtomicSequenceService $sequenceService
    ) {}

    /**
     * Create a dynamic payment gateway transaction and QR intent.
     *
     * @param  array<string, mixed>  $data
     * @throws DomainException
     */
    public function execute(array $data, ?int $sessionId = null): PaymentTransaction
    {
        $amount = (float) ($data['amount'] ?? 0.00);
        if ($amount <= 0) {
            throw new DomainException('Payment transaction amount must be greater than zero.');
        }

        $provider = $data['provider'] ?? 'kbzpay';
        $driver = $this->gatewayManager->driver($provider);

        $orderNo = $data['order_no'] ?? ('ORD-' . time());
        $salesOrderId = $data['sales_order_id'] ?? null;

        $intent = $driver->generateQrIntent($amount, $orderNo, $data['metadata'] ?? []);

        $txnNo = $this->sequenceService->getNextSequence('TXN');

        return PaymentTransaction::create([
            'transaction_no' => $txnNo,
            'pos_session_id' => $sessionId,
            'sales_order_id' => $salesOrderId,
            'provider' => $intent['provider'],
            'payment_method' => 'QR',
            'amount' => $amount,
            'currency' => $intent['currency'] ?? 'MMK',
            'qr_payload' => $intent['qr_payload'],
            'status' => 'pending',
            'gateway_ref' => $intent['gateway_ref'],
            'metadata' => [
                'deeplink' => $intent['deeplink'] ?? null,
                'order_no' => $orderNo,
            ],
            'expires_at' => $intent['expires_at'] ?? now()->addMinutes(15),
        ]);
    }
}
