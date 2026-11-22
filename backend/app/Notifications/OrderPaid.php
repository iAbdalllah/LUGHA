<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Digital delivery receipt: what was bought, where it is, gift codes, and the invoice link. */
class OrderPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['items.package', 'items.giftCode']);
        $mail = (new MailMessage)
            ->subject(__('Your LUGHA order :number', ['number' => $order->number]))
            ->greeting(__('Hello!'))
            ->line(__('Thank you — your payment is confirmed.'));

        $owned = $order->items->where('kind', '!=', 'gift');
        if ($owned->isNotEmpty()) {
            $mail->line(__('These packages are now in your library:'));
            foreach ($owned as $item) {
                $mail->line('• '.$item->package->name);
            }
            $mail->line(__('Install them from the LUGHA app on your device.'));
        }
        foreach ($order->items->where('kind', 'gift') as $item) {
            if ($item->giftCode) {
                $mail->line(__('Gift code for :name: :code', ['name' => $item->package->name, 'code' => $item->giftCode->code_encrypted]));
            }
        }
        if ($order->items->contains('kind', 'gift')) {
            $mail->line(__('Share the gift code with the person you\'re giving it to. It works once.'));
        }

        return $mail->action(__('View invoice'), route('orders.show', ['order' => $order, 'locale' => $notifiable->preferredLocale() ?? 'ar']));
    }
}
