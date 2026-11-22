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
 * Moyasar hosted invoices: the buyer pays on Moyasar's page (mada, cards, Apple Pay, STC Pay),
 * so card data never touches LUGHA. Docs: https://docs.moyasar.com/api/invoices
 */
final class MoyasarGateway implements PaymentGateway
{
    public function methodChoices(): array
    {
        return [];
    }

    public function name(): string
    {
        return 'moyasar';
    }

    private function http(): PendingRequest
    {
        $key = config('payments.moyasar.secret_key');
        if (! $key) {
            throw new PaymentException('MOYASAR_SECRET_KEY is not configured.');
        }

        return Http::baseUrl(config('payments.moyasar.base_url'))
            ->withBasicAuth($key, '')
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 300, throw: false);
    }

    public function createCheckout(Order $order, Payment $payment, string $returnUrl): CheckoutSession
    {
        $res = $this->http()->post('/invoices', [
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'description' => 'LUGHA order '.$order->public_id,
            'success_url' => $returnUrl,
            'back_url' => $returnUrl,
            'callback_url' => route('webhooks.payments', 'moyasar'),
            'metadata' => ['order' => $order->public_id, 'payment' => $payment->idempotency_key],
        ]);

        if (! $res->successful() || ! $res->json('id') || ! $res->json('url')) {
            throw new PaymentException('Moyasar invoice creation failed: '.$res->status());
        }

        return new CheckoutSession($res->json('id'), $res->json('url'), $res->json());
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        $res = $this->http()->get('/invoices/'.$payment->gateway_ref);
        if (! $res->successful()) {
            throw new PaymentException('Moyasar invoice lookup failed: '.$res->status());
        }
        $data = $res->json();
        $paid = collect($data['payments'] ?? [])->firstWhere('status', 'paid');

        $status = match ($data['status'] ?? null) {
            'paid' => PaymentStatus::Paid,
            'failed', 'expired', 'canceled' => PaymentStatus::Failed,
            'refunded' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending,
        };

        return new GatewayStatus(
            $status,
            (int) ($data['amount'] ?? 0),
            (string) ($data['currency'] ?? ''),
            $paid['source']['type'] ?? null,
            $data,
        );
    }

    public function parseWebhook(Request $request): ?WebhookEvent
    {
        $secret = (string) config('payments.moyasar.webhook_secret');
        $token = (string) $request->input('secret_token', '');
        if ($secret === '' || ! hash_equals($secret, $token)) {
            return null;
        }
        $invoiceId = $request->input('data.invoice_id') ?? $request->input('data.id');
        $eventId = (string) $request->input('id', '');
        if (! $invoiceId || $eventId === '') {
            return null;
        }

        return new WebhookEvent($eventId, (string) $invoiceId, $request->except('secret_token'));
    }

    public function refund(Payment $payment, int $amount): RefundResult
    {
        $paymentId = collect($payment->raw_last_status['payments'] ?? [])->firstWhere('status', 'paid')['id']
            ?? collect($payment->raw_last_status['payments'] ?? [])->first()['id'] ?? null;
        if (! $paymentId) {
            return new RefundResult(false, message: 'No captured Moyasar payment found on this invoice.');
        }

        $res = $this->http()->post("/payments/{$paymentId}/refund", ['amount' => $amount]);

        return $res->successful()
            ? new RefundResult(true, $paymentId)
            : new RefundResult(false, message: $res->json('message') ?? 'Refund failed ('.$res->status().')');
    }
}
