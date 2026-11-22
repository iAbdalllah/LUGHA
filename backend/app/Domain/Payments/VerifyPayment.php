<?php

namespace App\Domain\Payments;

use App\Domain\Commerce\FulfillOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Asks the gateway what happened and acts on it. Safe to call any number of times
 * (return URL, webhook, admin "re-check") — fulfilment is idempotent.
 */
final class VerifyPayment
{
    public function __construct(private GatewayManager $gateways, private FulfillOrder $fulfill) {}

    public function handle(Payment $payment): Payment
    {
        if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
            return $payment;
        }

        $result = $this->gateways->get($payment->gateway)->fetchStatus($payment);
        $payment->raw_last_status = $result->raw;
        $payment->method = $result->method ?? $payment->method;

        if ($result->status === PaymentStatus::Paid) {
            if ($result->amount !== $payment->amount || strtoupper($result->currency) !== $payment->currency) {
                Log::critical('Payment amount mismatch', ['payment' => $payment->id, 'gateway' => $result->amount.' '.$result->currency]);
                $payment->status = PaymentStatus::Failed;
                $payment->save();

                return $payment;
            }
            $payment->status = PaymentStatus::Paid;
            $payment->verified_at = now();
            $payment->save();
            $this->fulfill->handle($payment->order);
        } elseif ($result->status === PaymentStatus::Failed) {
            $payment->status = PaymentStatus::Failed;
            $payment->save();
            if ($payment->order->status === OrderStatus::AwaitingPayment) {
                $payment->order->update(['status' => OrderStatus::Failed]);
            }
        } else {
            $payment->save();
        }

        return $payment;
    }
}
