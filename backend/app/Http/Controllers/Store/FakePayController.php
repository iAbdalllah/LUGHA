<?php

namespace App\Http\Controllers\Store;

use App\Domain\Payments\Gateways\FakeGateway;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

/** Local stand-in for the gateway's hosted page. Routes are not registered in production. */
class FakePayController extends Controller
{
    private function payment(Request $request, string $ref): Payment
    {
        $payment = Payment::where('gateway', 'fake')->where('gateway_ref', $ref)->with('order')->firstOrFail();
        abort_unless($payment->order->user_id === $request->user()->id, 404);

        return $payment;
    }

    public function show(Request $request, string $ref)
    {
        return view('store.dev.fake-pay', ['payment' => $this->payment($request, $ref)]);
    }

    public function complete(Request $request, string $ref)
    {
        $payment = $this->payment($request, $ref);
        FakeGateway::setOutcome($ref, $request->input('outcome') === 'fail' ? PaymentStatus::Failed : PaymentStatus::Paid);

        return redirect()->route('checkout.return', $payment->idempotency_key);
    }
}
