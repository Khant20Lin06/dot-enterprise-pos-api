<?php

namespace App\Domains\SalesAndPos\Gateways;

use App\Domains\SalesAndPos\Gateways\Contracts\PaymentGatewayDriverInterface;
use App\Domains\SalesAndPos\Gateways\Drivers\KbzPayGatewayDriver;
use App\Domains\SalesAndPos\Gateways\Drivers\WavePayGatewayDriver;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * Resolve payment gateway driver by provider key.
     *
     * @throws InvalidArgumentException
     */
    public function driver(?string $provider = null): PaymentGatewayDriverInterface
    {
        $provider = strtolower(trim($provider ?: 'kbzpay'));

        return match ($provider) {
            'kbzpay', 'kpay' => app(KbzPayGatewayDriver::class),
            'wavepay', 'wave' => app(WavePayGatewayDriver::class),
            default => throw new InvalidArgumentException("Unsupported payment gateway provider: '{$provider}'."),
        };
    }
}
