<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Our reply to a contact-form message (A08 inbox), by email: the sender may have no account. Plain
 * text, split into paragraphs; Laravel's mail template escapes it.
 */
final class ContactReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $body,
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
        $mail = (new MailMessage)->subject(__('[:code] Reply from Oppam Matrimony', ['code' => $this->code]));
        foreach (preg_split('/\R{2,}/', $this->body) ?: [] as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail->line(__('Reply to this email if you need anything else. Please keep :code in the subject.', ['code' => $this->code]));
    }
}
