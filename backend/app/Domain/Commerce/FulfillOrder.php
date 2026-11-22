<?php

namespace App\Domain\Commerce;

use App\Domain\Entitlements\EntitlementService;
use App\Enums\EntitlementSource;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\GiftCode;
use App\Models\Order;
use App\Notifications\OrderPaid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Marks an order paid and delivers what was bought. Idempotent. */
final class FulfillOrder
{
    public function __construct(private EntitlementService $entitlements) {}

    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            /** @var Order $order */
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->isPaidOrRefunded()) {
                return $order;
            }

            $year = now()->year;
            $order->status = OrderStatus::Paid;
            $order->paid_at = now();
            $order->number = sprintf('LG-%d-%06d', $year, $order->id);
            $order->invoice_number = sprintf('INV-%d-%06d', $year, $order->id);
            $order->save();

            foreach ($order->items()->with('package.inclusions')->get() as $item) {
                if ($item->kind === 'gift') {
                    $code = strtoupper(implode('-', str_split(Str::random(16), 4)));
                    GiftCode::create([
                        'code_hash' => GiftCode::hashCode($code),
                        'code_hint' => substr(str_replace('-', '', $code), -4),
                        'code_encrypted' => $code,
                        'package_id' => $item->package_id,
                        'order_item_id' => $item->id,
                        'purchaser_id' => $order->user_id,
                        'expires_at' => now()->addYear(),
                    ]);

                    continue;
                }
                $source = $item->kind === 'upgrade' ? EntitlementSource::Upgrade : EntitlementSource::Purchase;
                $this->entitlements->grant($order->user, $item->package, $source, $item);
            }

            if ($order->coupon_id) {
                CouponRedemption::firstOrCreate(['coupon_id' => $order->coupon_id, 'order_id' => $order->id], ['user_id' => $order->user_id]);
                Coupon::whereKey($order->coupon_id)->increment('redeemed_count');
            }

            CartItem::where('user_id', $order->user_id)
                ->whereIn('package_id', $order->items->pluck('package_id'))
                ->delete();

            $order->user->notify(new OrderPaid($order)); // sent after commit

            return $order;
        });
    }
}
