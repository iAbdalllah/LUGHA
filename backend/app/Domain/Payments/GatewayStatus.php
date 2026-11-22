<?php

namespace App\Domain\Payments;

use App\Enums\PaymentStatus;

final class GatewayStatus
{
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $method = null,
        public readonly array $raw = [],
    ) {}
}
