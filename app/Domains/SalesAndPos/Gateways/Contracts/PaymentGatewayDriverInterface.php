<?php

namespace App\Domains\SalesAndPos\Gateways\Contracts;

interface PaymentGatewayDriverInterface
{
    /**
     * Create dynamic QR payment intent for POS customer display.
     *
     * @param  float  $amount
     * @param  string  $orderReference
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function generateQrIntent(float $amount, string $orderReference, array $metadata = []): array;

    /**
     * Verify incoming webhook payload from provider.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function verifyWebhook(array $payload): array;

    /**
     * Query transaction status from gateway.
     *
     * @param  string  $gatewayRef
     * @return array<string, mixed>
     */
    public function queryStatus(string $gatewayRef): array;

    /**
     * Initiate gateway refund.
     *
     * @param  string  $gatewayRef
     * @param  float  $amount
     * @param  string  $reason
     * @return array<string, mixed>
     */
    public function processRefund(string $gatewayRef, float $amount, string $reason): array;
}
