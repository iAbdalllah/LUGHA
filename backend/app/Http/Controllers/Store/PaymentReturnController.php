<?php

namespace App\Http\Controllers\Store;

use App\Domain\Payments\VerifyPayment;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentReturnController extends Controller
{
    public function __invoke(Request $request, string $key, VerifyPayment $verify)
    {
        $payment = Payment::where('idempotency_key', $key)->with('order')->firstOrFail();
        abort_if($request->user() && $payment->order->user_id !== $request->user()->id, 404);

        try {
            $verify->handle($payment);
        } catch (\Throwable $e) {
            // Webhook will settle it; the order page shows "awaiting payment" meanwhile.
            Log::warning('Payment verification on return failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', ['order' => $payment->order, 'locale' => app()->getLocale()]);
    }
}
