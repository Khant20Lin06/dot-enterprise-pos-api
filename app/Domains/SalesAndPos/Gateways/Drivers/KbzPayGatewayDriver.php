<?php

namespace App\Domains\SalesAndPos\Gateways\Drivers;

use App\Domains\SalesAndPos\Gateways\Contracts\PaymentGatewayDriverInterface;
use Illuminate\Support\Str;

class KbzPayGatewayDriver implements PaymentGatewayDriverInterface
{
    public function generateQrIntent(float $amount, string $orderReference, array $metadata = []): array
    {
        $prepayId = 'KBZ-' . strtoupper(Str::random(12));
        $formattedAmount = number_format($amount, 2, '.', '');

        // Standard Myanmar MMQR payload specification format
        $mmqrString = sprintf(
            '00020101021226500011com.kbzpay0115%s520459995303104540%s5802MM5915DOT_ENTERPRISE6006YANGON62240120%s6304',
            '959979000000',
            $formattedAmount,
            $orderReference
        );

        $deepLink = "kbzpay://qr?prepay_id={$prepayId}&order_id={$orderReference}&amount={$formattedAmount}";

        return [
            'provider' => 'kbzpay',
            'gateway_ref' => $prepayId,
            'qr_payload' => $mmqrString,
            'deeplink' => $deepLink,
            'amount' => (float) $amount,
            'currency' => 'MMK',
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ];
    }

    public function verifyWebhook(array $payload): array
    {
        $status = strtolower($payload['trade_status'] ?? $payload['status'] ?? '');
        $isSuccess = in_array($status, ['success', 'successful', 'paid', 'completed']);

        return [
            'success' => $isSuccess,
            'status' => $isSuccess ? 'successful' : 'failed',
            'gateway_ref' => $payload['prepay_id'] ?? $payload['gateway_ref'] ?? null,
            'amount' => isset($payload['amount']) ? (float) $payload['amount'] : null,
            'transaction_id' => $payload['transaction_id'] ?? ('KBZ-TXN-' . Str::random(8)),
            'raw' => $payload,
        ];
    }

    public function queryStatus(string $gatewayRef): array
    {
        return [
            'provider' => 'kbzpay',
            'gateway_ref' => $gatewayRef,
            'status' => 'successful',
            'verified_at' => now()->toIso8601String(),
        ];
    }

    public function processRefund(string $gatewayRef, float $amount, string $reason): array
    {
        $refundId = 'KBZ-REF-' . strtoupper(Str::random(10));

        return [
            'success' => true,
            'provider' => 'kbzpay',
            'refund_id' => $refundId,
            'original_gateway_ref' => $gatewayRef,
            'refund_amount' => (float) $amount,
            'reason' => $reason,
            'status' => 'refunded',
        ];
    }
}
