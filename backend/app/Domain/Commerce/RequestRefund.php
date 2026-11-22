<?php

namespace App\Domain\Commerce;

use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;

/** A customer's refund request becomes a billing ticket for Finance; the refund itself is done in the admin. */
final class RequestRefund
{
    public function handle(User $user, Order $order, int $orderItemId, ?string $reason): SupportTicket
    {
        $item = $order->items()->whereKey($orderItemId)->first();
        if (! $order->isPaidOrRefunded() || ! $item || $item->refundableAmount() <= 0) {
            throw new CheckoutException(__('refund.nothing_to_refund'));
        }
        if (SupportTicket::where('order_id', $order->id)->where('category', 'billing')->whereIn('status', ['open', 'pending_user'])->exists()) {
            throw new CheckoutException(__('A refund request for this order is already open.'));
        }

        $ticket = $user->supportTickets()->create([
            'order_id' => $order->id, 'package_id' => $item->package_id, 'category' => 'billing', 'status' => 'open', 'priority' => 'high',
            'subject' => __('Refund request: :order', ['order' => $order->number]),
        ]);
        $ticket->messages()->create([
            'author_id' => $user->id,
            'body' => __('Refund requested for :item.', ['item' => $item->package->name]).($reason ? "\n".__('Reason given: :reason', ['reason' => $reason]) : ''),
        ]);

        return $ticket;
    }
}
