<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\CheckoutSession;
use App\Domain\Payments\GatewayStatus;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\RefundResult;
use App\Domain\Payments\WebhookEvent;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Local/test stand-in for a hosted payment page. Refused in production by GatewayManager.
 * The "hosted page" is /dev/fake-pay/{ref}, where the developer chooses success or failure.
 */
final class FakeGateway implements PaymentGateway
{
    public function methodChoices(): array
    {
        return [];
    }

    public function name(): string
    {
        return 'fake';
    }

    public static function setOutcome(string $ref, PaymentStatus $status): void
    {
        Cache::put("fakepay:$ref", $status->value, now()->addDay());
    }

    public function createCheckout(Order $order, Payment $payment, string $returnUrl): CheckoutSession
    {
        $ref = 'fake_'.Str::lower(Str::random(16));
        Cache::put("fakepay:$ref:return", $returnUrl, now()->addDay());

        return new CheckoutSession($ref, route('dev.fake-pay', $ref));
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        $status = PaymentStatus::tryFrom((string) Cache::get("fakepay:{$payment->gateway_ref}")) ?? PaymentStatus::Pending;

        return new GatewayStatus($status, $payment->amount, $payment->currency, 'fake', ['id' => $payment->gateway_ref]);
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $ref = (string) $request->input('ref');

        return $ref !== '' ? new WebhookEvent('fake_evt_'.$ref.'_'.$request->input('n', 1), $ref, $request->all()) : null;
    }

    public function refund(Payment $payment, int $amount): RefundResult
    {
        return new RefundResult(true, 'fake_refund_'.Str::lower(Str::random(8)));
    }
}
