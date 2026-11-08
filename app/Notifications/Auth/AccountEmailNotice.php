<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\AccountSecurityChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A security notice to an address the account is leaving (M14, P8.2b) — e.g. "your email was
 * removed", sent to the address that was removed. Plain mail on an on-demand route.
 */
final class AccountEmailNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AccountSecurityChange $change)
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
            ->subject($this->change->title().' – '.config('oppam.site.name'))
            ->line($this->change->body())
            ->line(__('If this wasn’t you, reset your password now and contact our support team.'))
            ->action(__('Reset password'), route('password.forgot'));
    }
}
