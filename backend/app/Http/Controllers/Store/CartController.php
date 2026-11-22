<?php

namespace App\Http\Controllers\Store;

use App\Domain\Commerce\CartPricer;
use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\LocalizationPackage;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public static function sessionCoupon(): ?Coupon
    {
        $code = session('cart.coupon');

        return $code ? Coupon::where('code', $code)->first() : null;
    }

    public function show(Request $request, CartPricer $pricer)
    {
        return view('store.cart', ['quote' => $pricer->quoteCart($request->user(), self::sessionCoupon()),
            'items' => $request->user()->cartItems()->with('package.game')->get()]);
    }

    public function add(Request $request)
    {
        $data = $request->validate(['package' => ['required', 'string', 'exists:localization_packages,slug'], 'as_gift' => ['sometimes', 'boolean']]);
        $package = LocalizationPackage::where('slug', $data['package'])->firstOrFail();

        if (! $package->isPurchasable()) {
            return back()->withErrors(['cart' => __('cart.not_available', ['name' => $package->name])]);
        }
        $gift = (bool) ($data['as_gift'] ?? false);
        if (! $gift && $request->user()->owns($package)) {
            return back()->withErrors(['cart' => __('cart.already_owned', ['name' => $package->name])]);
        }

        CartItem::firstOrCreate(['user_id' => $request->user()->id, 'package_id' => $package->id, 'as_gift' => $gift]);

        return $request->boolean('buy_now') ? redirect()->route('checkout.show') : back()->with('status', __('Added to cart'));
    }

    public function remove(Request $request, CartItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);
        $item->delete();

        return back();
    }

    public function applyCoupon(Request $request, CartPricer $pricer)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $coupon = Coupon::where('code', strtoupper(trim($data['code'])))->first();
        if (! $coupon) {
            return back()->withErrors(['code' => __('cart.coupon_invalid')]);
        }
        $quote = $pricer->quoteCart($request->user(), $coupon);
        if ($quote->couponError) {
            return back()->withErrors(['code' => $quote->couponError]);
        }
        session(['cart.coupon' => $coupon->code]);

        return back()->with('status', __('Coupon applied'));
    }

    public function removeCoupon()
    {
        session()->forget('cart.coupon');

        return back();
    }
}
