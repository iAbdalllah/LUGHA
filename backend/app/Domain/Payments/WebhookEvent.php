<?php

namespace App\Domain\Payments;

final class WebhookEvent
{
    public function __construct(public readonly string $eventId, public readonly string $gatewayRef, public readonly array $payload) {}
}
