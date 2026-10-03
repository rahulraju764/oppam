<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A message from the Oppam team to selected members (A03 bulk "send notification"). Mail only for
 * now (in-app + live with P3.2); subject and body are plain text typed by the admin, escaped by
 * the mail template.
 */
final class AdminMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $subject, public readonly string $body)
    {
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
            ->subject($this->subject)
            ->line($this->body);
    }
}
