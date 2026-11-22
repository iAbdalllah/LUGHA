<?php

namespace App\Http\Controllers\Store;

use App\Domain\Payments\GatewayManager;
use App\Domain\Payments\VerifyPayment;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Http\Request;

/**
 * Webhooks only tell us *which* payment to look at. The status always comes from a
 * server-to-server lookup (VerifyPayment), so a forged or replayed webhook can't mark anything paid.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayManager $gateways, VerifyPayment $verify)
    {
        $event = $gateways->get($gateway)->parseWebhook($request);
        if (! $event) {
            return response()->json(['error' => 'invalid'], 401);
        }

        // insertOrIgnore instead of catching a unique violation: on PostgreSQL a caught violation still aborts
        // any surrounding transaction.
        $inserted = PaymentWebhookEvent::query()->insertOrIgnore([
            'gateway' => $gateway, 'event_id' => $event->eventId,
            'payload' => json_encode($event->payload, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return response()->json(['status' => 'duplicate']);
        }
        $record = PaymentWebhookEvent::where('gateway', $gateway)->where('event_id', $event->eventId)->first();

        $payment = Payment::where('gateway', $gateway)->where('gateway_ref', $event->gatewayRef)->first();
        if ($payment) {
            $verify->handle($payment);
        }
        $record->update(['processed_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
