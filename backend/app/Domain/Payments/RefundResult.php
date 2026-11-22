<?php

namespace App\Domain\Payments;

final class RefundResult
{
    public function __construct(public readonly bool $ok, public readonly ?string $gatewayRef = null, public readonly ?string $message = null) {}
}
