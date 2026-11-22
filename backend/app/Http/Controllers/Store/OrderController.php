<?php

namespace App\Http\Controllers\Store;

use App\Domain\Commerce\CheckoutException;
use App\Domain\Commerce\RequestRefund;
use App\Domain\Payments\PaymentException;
use App\Domain\Payments\StartPayment;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        return view('store.orders.index', ['orders' => $request->user()->orders()->with('items.package.game')->latest()->paginate(15)]);
    }

    public function show(Request $request, Order $order)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $order->load(['items.package.game', 'items.giftCode', 'payments', 'coupon', 'refunds.orderItem.package']);

        return view('store.orders.show', ['order' => $order, 'seller' => config('lugha.seller')]);
    }

    public function pay(Request $request, Order $order, StartPayment $start)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        try {
            return redirect()->away($start->handle($order, $request->input('method'))->checkout_url);
        } catch (PaymentException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }
    }

    public function requestRefund(Request $request, Order $order, RequestRefund $action)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $data = $request->validate(['item' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:2000']]);
        try {
            $ticket = $action->handle($request->user(), $order, (int) $data['item'], $data['reason'] ?? null);
        } catch (CheckoutException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return redirect()->route('support.show', $ticket)->with('status', __('Your refund request was sent. Our team will reply by email.'));
    }
}
