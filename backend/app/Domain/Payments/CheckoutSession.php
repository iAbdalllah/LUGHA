<?php

namespace App\Domain\Payments;

final class CheckoutSession
{
    public function __construct(public readonly string $gatewayRef, public readonly string $url, public readonly array $raw = []) {}
}
