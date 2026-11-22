<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\CheckoutSession;
use App\Domain\Payments\GatewayStatus;
use App\Domain\Payments\PaymentException;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\RefundResult;
use App\Domain\Payments\WebhookEvent;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * HyperPay COPYandPAY. The card form is HyperPay's widget (an iframe) rendered on our /checkout/pay page,
 * so card data goes straight to HyperPay. Each brand group has its own entity ID (mada is separate).
 * Docs: https://wordpresshyperpay.docs.oppwa.com/integrations/widget
 */
final class HyperPayGateway implements PaymentGateway
{
    // HyperPay result-code families (from their "Result codes" reference).
    private const SUCCESS = '/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.1[12]0)/';

    private const PENDING = '/^(000\.200|800\.400\.5|100\.400\.500)/';

    public function name(): string
    {
        return 'hyperpay';
    }

    public function methodChoices(): array
    {
        return collect(['mada' => 'mada', 'card' => 'Visa / Mastercard', 'applepay' => 'Apple Pay'])
            ->filter(fn ($label, $method) => filled(config("payments.hyperpay.entities.$method")))
            ->all();
    }

    public function widgetBrands(string $method): string
    {
        return ['mada' => 'MADA', 'card' => 'VISA MASTER', 'applepay' => 'APPLEPAY'][$method] ?? 'VISA MASTER';
    }

    public function widgetScriptUrl(Payment $payment): string
    {
        return config('payments.hyperpay.base_url').'/v1/paymentWidgets.js?checkoutId='.urlencode($payment->gateway_ref);
    }

    private function entity(Payment $payment): string
    {
        $entity = config('payments.hyperpay.entities.'.($payment->method ?? 'card'));
        if (! $entity) {
            throw new PaymentException("HyperPay entity for [{$payment->method}] is not configured.");
        }

        return $entity;
    }

    private function http(): PendingRequest
    {
        $token = config('payments.hyperpay.access_token');
        if (! $token) {
            throw new PaymentException('HYPERPAY_ACCESS_TOKEN is not configured.');
        }

        return Http::baseUrl(config('payments.hyperpay.base_url'))->withToken($token)->asForm()->acceptJson()->timeout(20);
    }

    private static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    public function createCheckout(Order $order, Payment $payment, string $returnUrl): CheckoutSession
    {
        $user = $order->user;
        $res = $this->http()->post('/v1/checkouts', array_filter([
            'entityId' => $this->entity($payment),
            'amount' => self::decimal($payment->amount),
            'currency' => $payment->currency,
            'paymentType' => 'DB',
            'merchantTransactionId' => $payment->idempotency_key,
            'customer.email' => $user->email,
            'customer.givenName' => $order->billing['name'] ?? $user->name,
            'billing.country' => $order->billing['country'] ?? 'SA',
            'testMode' => config('payments.hyperpay.test_mode') ? 'EXTERNAL' : null,
        ]));

        if (! $res->successful() || ! $res->json('id') || ! preg_match(self::PENDING, (string) $res->json('result.code'))) {
            throw new PaymentException('HyperPay checkout failed: '.$res->json('result.code').' '.$res->json('result.description'));
        }

        return new CheckoutSession($res->json('id'), route('checkout.pay', $payment->idempotency_key), $res->json());
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        $res = $this->http()->get('/v1/checkouts/'.urlencode($payment->gateway_ref).'/payment', ['entityId' => $this->entity($payment)]);
        $code = (string) $res->json('result.code');

        $status = match (true) {
            (bool) preg_match(self::SUCCESS, $code) => PaymentStatus::Paid,
            (bool) preg_match(self::PENDING, $code), $code === '' => PaymentStatus::Pending,
            default => PaymentStatus::Failed,
        };

        return new GatewayStatus(
            $status,
            (int) round(((float) $res->json('amount', 0)) * 100),
            (string) $res->json('currency', $payment->currency),
            strtolower((string) $res->json('paymentBrand')) ?: $payment->method,
            $res->json() ?? [],
        );
    }

    /** HyperPay webhooks are AES-256-GCM encrypted with the merchant's webhook key. */
    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $key = (string) config('payments.hyperpay.webhook_key');
        $iv = (string) $request->header('X-Initialization-Vector');
        $tag = (string) $request->header('X-Authentication-Tag');
        if ($key === '' || $iv === '' || $tag === '' || ! ctype_xdigit($key.$iv.$tag)) {
            return null;
        }

        $plain = openssl_decrypt((string) hex2bin(trim($request->getContent())), 'aes-256-gcm', hex2bin($key), OPENSSL_RAW_DATA, hex2bin($iv), hex2bin($tag));
        $data = $plain === false ? null : json_decode($plain, true);
        $checkoutId = $data['payload']['ndc'] ?? null;
        if (! is_array($data) || ! $checkoutId) {
            return null;
        }

        return new WebhookEvent(
            ($data['payload']['id'] ?? $checkoutId).':'.($data['payload']['result']['code'] ?? ''),
            (string) $checkoutId,
            $data,
        );
    }

    public function refund(Payment $payment, int $amount): RefundResult
    {
        $paymentId = $payment->raw_last_status['id'] ?? null;
        if (! $paymentId) {
            return new RefundResult(false, message: 'No captured HyperPay payment id on record.');
        }

        $res = $this->http()->post('/v1/payments/'.urlencode($paymentId), [
            'entityId' => $this->entity($payment),
            'amount' => self::decimal($amount),
            'currency' => $payment->currency,
            'paymentType' => 'RF',
        ]);

        return preg_match(self::SUCCESS, (string) $res->json('result.code'))
            ? new RefundResult(true, $res->json('id'))
            : new RefundResult(false, message: $res->json('result.description') ?? 'Refund failed');
    }
}
