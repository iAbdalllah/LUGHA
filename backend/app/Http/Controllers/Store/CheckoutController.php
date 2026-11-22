<?php

namespace App\Http\Controllers\Store;

use App\Domain\Commerce\CartPricer;
use App\Domain\Commerce\CheckoutException;
use App\Domain\Commerce\CreateOrderFromCart;
use App\Domain\Commerce\FulfillOrder;
use App\Domain\Payments\GatewayManager;
use App\Domain\Payments\PaymentException;
use App\Domain\Payments\StartPayment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function show(Request $request, CartPricer $pricer)
    {
        $quote = $pricer->quoteCart($request->user(), CartController::sessionCoupon());
        if ($quote->lines === []) {
            return redirect()->route('cart.show');
        }

        return view('store.checkout', ['quote' => $quote, 'user' => $request->user(),
            'methods' => app(GatewayManager::class)->default()->methodChoices()]);
    }

    public function store(Request $request, CreateOrderFromCart $create, StartPayment $start, FulfillOrder $fulfill)
    {
        $billing = $request->validate([
            'billing_name' => ['required', 'string', 'max:120'],
            'billing_country' => ['required', 'string', 'size:2'],
            'billing_vat_number' => ['nullable', 'string', 'regex:/^3\d{13}3$/'],
            'accept_terms' => ['accepted'],
            'method' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $order = $create->handle($request->user(), CartController::sessionCoupon(), [
                'name' => $billing['billing_name'], 'country' => $billing['billing_country'], 'vat_number' => $billing['billing_vat_number'] ?? null,
            ]);
        } catch (CheckoutException $e) {
            return redirect()->route('cart.show')->withErrors(['cart' => $e->getMessage()]);
        }
        session()->forget('cart.coupon');

        // A fully discounted order needs no payment.
        if ($order->total_amount === 0) {
            $fulfill->handle($order);

            return redirect()->route('orders.show', $order)->with('status', __('Your packages are in your library.'));
        }

        try {
            $payment = $start->handle($order, $billing['method'] ?? null);
        } catch (PaymentException $e) {
            Log::error('Payment start failed', ['order' => $order->id, 'error' => $e->getMessage()]);

            return redirect()->route('orders.show', $order)->withErrors(['payment' => __('checkout.gateway_unavailable')]);
        }

        return redirect()->away($payment->checkout_url);
    }
}
