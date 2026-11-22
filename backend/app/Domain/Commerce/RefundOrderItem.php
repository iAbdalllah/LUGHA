<?php

namespace App\Domain\Commerce;

use App\Domain\Entitlements\EntitlementService;
use App\Domain\Payments\GatewayManager;
use App\Domain\Payments\PaymentException;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\GiftCode;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Full refund of one order line through the gateway, then revocation of what it granted. */
final class RefundOrderItem
{
    public function __construct(private GatewayManager $gateways, private EntitlementService $entitlements) {}

    public function handle(OrderItem $item, string $reason, User $staff): Refund
    {
        $order = $item->order;
        $payment = $order->payments()->whereIn('status', [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded])->first();
        $amount = $item->refundableAmount();

        if (! $order->isPaidOrRefunded() || $amount <= 0) {
            throw new PaymentException(__('refund.nothing_to_refund'));
        }
        if (! $payment) {
            throw new PaymentException(__('refund.no_payment')); // e.g. 100%-coupon order: revoke manually
        }

        $result = $this->gateways->get($payment->gateway)->refund($payment, $amount);

        $refund = Refund::create([
            'payment_id' => $payment->id,
            'order_item_id' => $item->id,
            'amount' => $amount,
            'reason' => $reason,
            'status' => $result->ok ? 'processed' : 'failed',
            'gateway_ref' => $result->gatewayRef,
            'requested_by' => $staff->id,
            'processed_by' => $staff->id,
            'processed_at' => $result->ok ? now() : null,
            'vat_amount' => Money::vatIncluded($amount),
        ]);

        if (! $result->ok) {
            throw new PaymentException($result->message ?? __('refund.failed'));
        }

        $refund->update(['credit_note_number' => sprintf('CN-%d-%06d', now()->year, $refund->id)]);

        DB::transaction(function () use ($item, $order, $payment, $amount) {
            $item->increment('refunded_amount', $amount);
            $order->increment('refunded_amount', $amount);
            $payment->increment('refunded_amount', $amount);

            $fully = $order->refresh()->refunded_amount >= $order->total_amount;
            $order->update(['status' => $fully ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded]);
            $payment->update(['status' => $payment->refresh()->refunded_amount >= $payment->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded]);

            Entitlement::where('order_item_id', $item->id)->whereNull('parent_entitlement_id')->where('status', 'active')
                ->get()->each(fn ($e) => $this->entitlements->revoke($e, 'refund'));

            if ($gift = GiftCode::where('order_item_id', $item->id)->first()) {
                $gift->update(['revoked_at' => now()]);
                if ($gift->redeemed_by) {
                    Entitlement::where('user_id', $gift->redeemed_by)->where('package_id', $gift->package_id)
                        ->where('source', 'gift')->where('status', 'active')
                        ->get()->each(fn ($e) => $this->entitlements->revoke($e, 'gift_refunded'));
                }
            }
        });

        AuditLog::record('order_item.refunded', $item, ['amount' => $amount]);

        return $refund;
    }
}
