<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * "Our team accessed your account" (PRD A01: member emailed; owner decision 2026-10-02: at the
 * start). Neutral — the time only; the admin's internal reason and name are never sent.
 * Verified email only (the Action checks).
 */
final class ImpersonationStarted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Carbon $at)
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
            ->subject(__('A member of our team accessed your Oppam Matrimony account'))
            ->line(__('On :when IST, a member of the Oppam support team signed in to your account to help with a support request. The access ends automatically within 30 minutes.', [
                'when' => $this->at->copy()->timezone((string) config('oppam.display_timezone'))->format('j M Y, g:i A'),
            ]))
            ->line(__('Our team can\'t change your password, email or payments, and can\'t contact anyone on your behalf.'))
            ->line(__('If you did not ask us for help, please contact our support team.'));
    }
}
