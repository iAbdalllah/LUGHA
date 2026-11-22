<?php

namespace App\Domain\Commerce;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

final class CreateOrderFromCart
{
    public function __construct(private CartPricer $pricer) {}

    public function handle(User $user, ?Coupon $coupon, array $billing): Order
    {
        if (! $user->hasVerifiedEmail()) {
            throw new CheckoutException(__('checkout.verify_email_first'));
        }

        $quote = $this->pricer->quoteCart($user, $coupon);
        if (! $quote->isCheckoutable()) {
            throw new CheckoutException($quote->errors()[0] ?? __('cart.empty'));
        }
        if ($coupon && $quote->couponError) {
            throw new CheckoutException($quote->couponError);
        }

        return DB::transaction(function () use ($user, $quote, $billing) {
            $order = Order::create([
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'currency' => $quote->currency,
                'subtotal_amount' => $quote->subtotal(),
                'discount_amount' => $quote->discount(),
                'vat_amount' => $quote->vat(),
                'total_amount' => $quote->total(),
                'coupon_id' => $quote->coupon?->id,
                'billing' => $billing,
            ]);

            foreach ($quote->lines as $line) {
                $package = $line->package;
                $order->items()->create([
                    'package_id' => $package->id,
                    'kind' => $line->kind,
                    'upgrade_from_package_id' => $line->upgradeFromId,
                    'list_amount' => $line->listAmount,
                    'unit_amount' => $line->net(),
                    'discount_amount' => $line->discount,
                    'vat_amount' => Money::vatIncluded($line->net()),
                    // Terms frozen at purchase time: later edits to the package never change what was sold.
                    'snapshot' => [
                        'name' => $package->getTranslations('name'),
                        'game' => $package->game->getTranslations('title'),
                        'categories' => $package->effectiveCategories()->pluck('slug')->all(),
                        'update_policy' => $package->update_policy->value,
                        'update_months' => $package->update_months,
                        'current_major' => $package->current_major,
                    ],
                ]);
            }

            return $order;
        });
    }
}
