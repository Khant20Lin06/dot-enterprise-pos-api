<?php

namespace App\Domains\SalesAndPos\Gateways\Drivers;

use App\Domains\SalesAndPos\Gateways\Contracts\PaymentGatewayDriverInterface;
use Illuminate\Support\Str;

class WavePayGatewayDriver implements PaymentGatewayDriverInterface
{
    public function generateQrIntent(float $amount, string $orderReference, array $metadata = []): array
    {
        $waveOrderId = 'WAVE-' . strtoupper(Str::random(12));
        $formattedAmount = number_format($amount, 2, '.', '');

        // Standard Wave Money Deeplink / Dynamic QR Payload
        $waveQrString = "wavemoney://payment?order_id={$waveOrderId}&merchant_id=DOT_ENTERPRISE&amount={$formattedAmount}&ref={$orderReference}";

        return [
            'provider' => 'wavepay',
            'gateway_ref' => $waveOrderId,
            'qr_payload' => $waveQrString,
            'deeplink' => $waveQrString,
            'amount' => (float) $amount,
            'currency' => 'MMK',
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ];
    }

    public function verifyWebhook(array $payload): array
    {
        $status = strtolower($payload['status'] ?? $payload['payment_status'] ?? '');
        $isSuccess = in_array($status, ['success', 'successful', 'payment_confirmed', 'completed']);

        return [
            'success' => $isSuccess,
            'status' => $isSuccess ? 'successful' : 'failed',
            'gateway_ref' => $payload['order_id'] ?? $payload['gateway_ref'] ?? null,
            'amount' => isset($payload['amount']) ? (float) $payload['amount'] : null,
            'transaction_id' => $payload['transaction_id'] ?? ('WAVE-TXN-' . Str::random(8)),
            'raw' => $payload,
        ];
    }

    public function queryStatus(string $gatewayRef): array
    {
        return [
            'provider' => 'wavepay',
            'gateway_ref' => $gatewayRef,
            'status' => 'successful',
            'verified_at' => now()->toIso8601String(),
        ];
    }

    public function processRefund(string $gatewayRef, float $amount, string $reason): array
    {
        $refundId = 'WAVE-REF-' . strtoupper(Str::random(10));

        return [
            'success' => true,
            'provider' => 'wavepay',
            'refund_id' => $refundId,
            'original_gateway_ref' => $gatewayRef,
            'refund_amount' => (float) $amount,
            'reason' => $reason,
            'status' => 'refunded',
        ];
    }
}
