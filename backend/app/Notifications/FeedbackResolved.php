<?php

namespace App\Notifications;

use App\Models\TranslationFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a player or contributor what happened to their report or suggestion. */
class FeedbackResolved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TranslationFeedback $feedback)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fb = $this->feedback;
        $mail = (new MailMessage)
            ->subject(__('community.mail.subject.'.$fb->status))
            ->greeting(__('Hello!'))
            ->line(__('community.mail.line.'.$fb->kind.'.'.$fb->status, ['package' => $fb->package?->name ?? '']))
            ->line('“'.$fb->translation->source_text.'”');
        if ($fb->applied_text) {
            $mail->line(__('community.mail.applied').' '.$fb->applied_text);
        }
        if ($fb->resolution_note) {
            $mail->line(__('community.mail.note').' '.$fb->resolution_note);
        }

        return $mail->line(__('community.mail.thanks'));
    }
}
