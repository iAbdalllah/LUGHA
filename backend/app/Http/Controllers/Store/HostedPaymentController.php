<?php

namespace App\Http\Controllers\Store;

use App\Domain\Payments\Gateways\HyperPayGateway;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

/** Renders the provider's embedded card widget. The widget posts card data to the provider, not to us. */
class HostedPaymentController extends Controller
{
    public function __invoke(Request $request, string $key, HyperPayGateway $hyperpay)
    {
        $payment = Payment::where('idempotency_key', $key)->with('order')->firstOrFail();
        abort_unless($payment->order->user_id === $request->user()->id && $payment->gateway === 'hyperpay', 404);
        if (! $payment->order->status->isPayable()) {
            return redirect()->route('orders.show', ['order' => $payment->order, 'locale' => app()->getLocale()]);
        }

        return view('store.pay', [
            'payment' => $payment,
            'scriptUrl' => $hyperpay->widgetScriptUrl($payment),
            'brands' => $hyperpay->widgetBrands($payment->method ?? 'card'),
            'returnUrl' => route('checkout.return', $payment->idempotency_key),
        ]);
    }
}
