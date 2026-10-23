<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Our reply to a ticket from the public contact form, by email (the visitor has no account). The
 * reply text goes in the email — there is nowhere else for a visitor to read it; internal notes
 * never do. Replying to the email lands in support's mailbox (email-in comes later).
 */
final class GuestTicketReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ticketCode,
        public readonly string $subject,
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
        $mail = (new MailMessage)->subject(__('[:code] :subject', ['code' => $this->ticketCode, 'subject' => $this->subject]));
        foreach (preg_split('/\R{2,}/', $this->body) ?: [] as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail->line(__('Reply to this email if you need anything else. Please keep :code in the subject.', ['code' => $this->ticketCode]));
    }
}
