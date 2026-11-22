<?php

namespace App\Domain\Commerce;

use App\Models\Coupon;
use App\Models\LocalizationPackage;
use App\Models\PackageUpgrade;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single source of truth for what a cart costs. Used for display AND when creating the order,
 * so the browser never supplies a price.
 */
final class CartPricer
{
    /**
     * @param  Collection<int, array{package: LocalizationPackage, as_gift: bool}>  $items
     */
    public function quote(User $user, Collection $items, ?Coupon $coupon = null, string $currency = 'SAR'): Quote
    {
        $owned = $user->activeEntitlements()->pluck('package_id')->all();
        $lines = [];

        foreach ($items as $item) {
            /** @var LocalizationPackage $package */
            $package = $item['package'];
            $asGift = (bool) $item['as_gift'];
            $price = $package->priceFor($currency);

            if (! $package->isPurchasable($currency) || ! $price) {
                $lines[] = new QuoteLine($package, $asGift ? 'gift' : 'purchase', 0, 0, error: __('cart.not_available', ['name' => $package->name]));

                continue;
            }

            $list = $price->effectiveAmount();

            if ($asGift) {
                $lines[] = new QuoteLine($package, 'gift', $list, $list);

                continue;
            }

            if (in_array($package->id, $owned, true)) {
                $lines[] = new QuoteLine($package, 'purchase', $list, $list, error: __('cart.already_owned', ['name' => $package->name]));

                continue;
            }

            $upgrade = $owned === [] ? null : PackageUpgrade::query()
                ->where('to_package_id', $package->id)
                ->where('currency', $currency)
                ->whereIn('from_package_id', $owned)
                ->orderBy('amount')
                ->first();

            $lines[] = $upgrade && $upgrade->amount < $list
                ? new QuoteLine($package, 'upgrade', $list, $upgrade->amount, $upgrade->from_package_id)
                : new QuoteLine($package, 'purchase', $list, $list);
        }

        [$coupon, $couponError] = $this->applyCoupon($user, $lines, $coupon);

        return new Quote($lines, $currency, $coupon, $couponError);
    }

    /** @param QuoteLine[] $lines */
    private function applyCoupon(User $user, array $lines, ?Coupon $coupon): array
    {
        if (! $coupon) {
            return [null, null];
        }

        $error = $this->couponProblem($user, $coupon, $lines);
        if ($error) {
            return [null, $error];
        }

        $eligible = array_values(array_filter($lines, fn (QuoteLine $l) => ! $l->error && $coupon->appliesTo($l->package)));
        $eligibleTotal = array_sum(array_map(fn (QuoteLine $l) => $l->unitAmount, $eligible));
        if ($eligibleTotal === 0) {
            return [null, __('cart.coupon_not_applicable')];
        }

        if ($coupon->kind === 'percent') {
            foreach ($eligible as $l) {
                $l->discount = intdiv($l->unitAmount * min($coupon->value, 100), 100);
            }
        } else {
            // Fixed amount: spread across eligible lines in proportion to price; remainder to the last line.
            $remaining = min($coupon->value, $eligibleTotal);
            foreach ($eligible as $i => $l) {
                $share = $i === array_key_last($eligible)
                    ? $remaining
                    : intdiv(min($coupon->value, $eligibleTotal) * $l->unitAmount, $eligibleTotal);
                $l->discount = min($share, $l->unitAmount);
                $remaining -= $l->discount;
            }
        }

        return [$coupon, null];
    }

    private function couponProblem(User $user, Coupon $coupon, array $lines): ?string
    {
        $now = now();
        if (! $coupon->is_active || ($coupon->starts_at && $coupon->starts_at->gt($now))) {
            return __('cart.coupon_invalid');
        }
        if ($coupon->ends_at && $coupon->ends_at->lte($now)) {
            return __('cart.coupon_expired');
        }
        if ($coupon->max_redemptions !== null && $coupon->redeemed_count >= $coupon->max_redemptions) {
            return __('cart.coupon_used_up');
        }
        if ($coupon->redemptions()->where('user_id', $user->id)->count() >= $coupon->per_user_limit) {
            return __('cart.coupon_already_used');
        }
        $subtotal = array_sum(array_map(fn (QuoteLine $l) => $l->unitAmount, $lines));
        if ($coupon->min_order_amount && $subtotal < $coupon->min_order_amount) {
            return __('cart.coupon_minimum');
        }

        return null;
    }

    public function quoteCart(User $user, ?Coupon $coupon = null): Quote
    {
        $items = $user->cartItems()->with(['package.game', 'package.prices'])->get()
            ->map(fn ($ci) => ['package' => $ci->package, 'as_gift' => $ci->as_gift]);

        return $this->quote($user, $items, $coupon);
    }
}
