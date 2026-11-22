<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\HyperPayGateway;
use App\Domain\Payments\Gateways\MoyasarGateway;

final class GatewayManager
{
    public function default(): PaymentGateway
    {
        return $this->get(config('payments.default'));
    }

    public function get(string $name): PaymentGateway
    {
        return match ($name) {
            'moyasar' => app(MoyasarGateway::class),
            'hyperpay' => app(HyperPayGateway::class),
            'fake' => $this->fake(),
            default => throw new PaymentException("Unknown payment gateway [$name]."),
        };
    }

    private function fake(): FakeGateway
    {
        // A fake success must never be possible in production.
        if (app()->environment('production')) {
            throw new PaymentException('The fake payment gateway is disabled in production.');
        }

        return app(FakeGateway::class);
    }
}
