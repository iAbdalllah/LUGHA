<?php

namespace App\Domain\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;

final class StartPayment
{
    public function __construct(private GatewayManager $gateways) {}

    public function handle(Order $order, ?string $method = null): Payment
    {
        if (! $order->status->isPayable()) {
            throw new PaymentException(__('checkout.not_payable'));
        }

        $gateway = $this->gateways->default();
        $choices = $gateway->methodChoices();
        if ($choices !== [] && ! array_key_exists((string) $method, $choices)) {
            $method = array_key_first($choices);
        }
        $payment = $order->payments()->create([
            'gateway' => $gateway->name(),
            'idempotency_key' => (string) Str::ulid(),
            'status' => PaymentStatus::Initiated,
            'method' => $choices === [] ? null : $method,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ]);

        $session = $gateway->createCheckout($order, $payment, route('checkout.return', $payment->idempotency_key));
        $payment->update(['gateway_ref' => $session->gatewayRef, 'checkout_url' => $session->url, 'status' => PaymentStatus::Pending]);
        $order->update(['status' => OrderStatus::AwaitingPayment]);

        return $payment;
    }
}
