<?php

namespace App\Domain\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /**
     * Payment methods the buyer must choose between before checkout (method => label key), or [] when the
     * provider's own page offers every method (Moyasar).
     */
    public function methodChoices(): array;

    /** Create a hosted checkout for the payment. Returns the gateway reference and the URL to send the buyer to. */
    public function createCheckout(Order $order, Payment $payment, string $returnUrl): CheckoutSession;

    /** Server-to-server status lookup. This — never the browser redirect — decides whether money arrived. */
    public function fetchStatus(Payment $payment): GatewayStatus;

    /** Validate an incoming webhook. Returns the event id and the gateway reference it concerns, or null if invalid. */
    public function parseWebhook(Request $request): ?WebhookEvent;

    public function refund(Payment $payment, int $amount): RefundResult;
}
