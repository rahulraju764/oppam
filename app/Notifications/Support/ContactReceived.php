<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new contact-form message, emailed to the support address (M12 "stored, emailed to support").
 * Only the code, topic and a link: the message itself is read in the A08 inbox, signed in.
 */
final class ContactReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $topic,
    ) {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('[:code] New contact message: :topic', ['code' => $this->code, 'topic' => $this->topic]))
            ->line(__('A new message arrived through the contact form.'))
            ->action(__('Open the contact inbox'), route('admin.contact.index'));
    }
}
