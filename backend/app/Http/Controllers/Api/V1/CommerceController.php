<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Tracker;
use App\Domain\Commerce\CartPricer;
use App\Domain\Commerce\CheckoutException;
use App\Domain\Commerce\CreateOrderFromCart;
use App\Domain\Commerce\FulfillOrder;
use App\Domain\Commerce\RedeemGiftCode;
use App\Domain\Commerce\RequestRefund;
use App\Domain\Payments\StartPayment;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Http\Resources\V1\QuoteResource;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\LocalizationPackage;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommerceController extends Controller
{
    private function coupon(?string $code): ?Coupon
    {
        return $code ? Coupon::where('code', strtoupper(trim($code)))->first() : null;
    }

    public function cart(Request $request, CartPricer $pricer)
    {
        $request->validate(['coupon' => ['nullable', 'string', 'max:40']]);

        return response()->json(QuoteResource::make($pricer->quoteCart($request->user(), $this->coupon($request->query('coupon')))));
    }

    public function addItem(Request $request, CartPricer $pricer)
    {
        $data = $request->validate(['package' => ['required', 'string', 'exists:localization_packages,slug'], 'as_gift' => ['sometimes', 'boolean']]);
        $package = LocalizationPackage::where('slug', $data['package'])->firstOrFail();
        $gift = (bool) ($data['as_gift'] ?? false);

        if (! $package->isPurchasable()) {
            throw new CheckoutException(__('cart.not_available', ['name' => $package->name]));
        }
        if (! $gift && $request->user()->owns($package)) {
            throw new CheckoutException(__('cart.already_owned', ['name' => $package->name]));
        }
        CartItem::firstOrCreate(['user_id' => $request->user()->id, 'package_id' => $package->id, 'as_gift' => $gift]);
        app(Tracker::class)->record('cart_add', $request, $package);

        return response()->json(QuoteResource::make($pricer->quoteCart($request->user())), 201);
    }

    public function removeItem(Request $request, string $package, CartPricer $pricer)
    {
        $request->user()->cartItems()->whereHas('package', fn ($q) => $q->where('slug', $package))
            ->when($request->has('as_gift'), fn ($q) => $q->where('as_gift', $request->boolean('as_gift')))->delete();

        return response()->json(QuoteResource::make($pricer->quoteCart($request->user())));
    }

    public function checkCoupon(Request $request, CartPricer $pricer)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $coupon = $this->coupon($data['code']);
        $quote = $pricer->quoteCart($request->user(), $coupon);
        if (! $coupon || $quote->couponError) {
            throw new CheckoutException($quote->couponError ?? __('cart.coupon_invalid'), 422);
        }

        return response()->json(QuoteResource::make($quote));
    }

    /** Requires an Idempotency-Key header: retries with the same key return the same order. */
    public function createOrder(Request $request, CreateOrderFromCart $create, FulfillOrder $fulfill)
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_if(strlen($key) < 8 || strlen($key) > 80, 400, 'An Idempotency-Key header (8–80 chars) is required.');
        $data = $request->validate([
            'coupon' => ['nullable', 'string', 'max:40'],
            'billing.name' => ['required', 'string', 'max:120'],
            'billing.country' => ['required', 'string', 'size:2'],
            'billing.vat_number' => ['nullable', 'string', 'regex:/^3\d{13}3$/'],
        ]);

        $cacheKey = "api:order:{$request->user()->id}:".hash('sha256', $key);
        if ($existing = Cache::get($cacheKey)) {
            return response()->json(['data' => new OrderResource(Order::with('items.package')->findOrFail($existing))]);
        }

        $order = $create->handle($request->user(), $this->coupon($data['coupon'] ?? null), $data['billing']);
        if ($order->total_amount === 0) {
            $order = $fulfill->handle($order);
        }
        Cache::put($cacheKey, $order->id, now()->addDay());

        return response()->json(['data' => new OrderResource($order->load('items.package'))], 201);
    }

    public function pay(Request $request, Order $order, StartPayment $start)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $payment = $start->handle($order, $request->input('method'));

        return response()->json(['payment_id' => $payment->idempotency_key, 'action' => ['type' => 'redirect', 'url' => $payment->checkout_url]]);
    }

    public function orders(Request $request)
    {
        $orders = $request->user()->orders()->with('items.package')->latest('id')->cursorPaginate(20);

        return response()->json(['data' => OrderResource::collection($orders->items()), 'next_cursor' => $orders->nextCursor()?->encode()]);
    }

    public function order(Request $request, Order $order)
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return response()->json(['data' => new OrderResource($order->load(['items.package', 'items.giftCode']))]);
    }

    public function redeemGift(Request $request, RedeemGiftCode $redeem)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $grant = $redeem->handle($request->user(), $data['code']);

        return response()->json(['package' => $grant->package->slug, 'status' => 'granted'], 201);
    }

    public function library(Request $request)
    {
        $grants = $request->user()->activeEntitlements()
            ->with(['package.game', 'package.versions' => fn ($q) => $q->where('build_status', 'released')->latest('released_at')])
            ->orderByDesc('granted_at')->get();

        return response()->json(['data' => $grants->map(function ($e) {
            $latest = $e->package->versions->first(fn ($v) => $e->covers($v));

            return [
                'package' => $e->package->slug, 'name' => $e->package->name,
                'game' => ['slug' => $e->package->game->slug, 'title' => $e->package->game->title],
                'source' => $e->source->value, 'granted_at' => $e->granted_at->toIso8601String(),
                'updates' => ['major' => $e->update_major, 'until' => $e->updates_until?->toIso8601String()],
                'latest_version' => $latest?->version,
            ];
        })]);
    }

    public function requestRefund(Request $request, Order $order, RequestRefund $action)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $data = $request->validate(['order_item' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $ticket = $action->handle($request->user(), $order, (int) $data['order_item'], $data['reason'] ?? null);

        return response()->json(['ticket' => $ticket->public_id, 'status' => 'received'], 202);
    }
}
