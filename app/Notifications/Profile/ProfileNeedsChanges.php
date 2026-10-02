<?php

declare(strict_types=1);

namespace App\Notifications\Profile;

use App\Enums\RejectReason;
use App\Enums\WizardStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your profile needs changes" (A04 reject / request changes, R-M02-5): the category's member
 * message, the moderator's note, and a link to the wizard step to fix. Mail for now (P3.2 adds
 * in-app + live).
 */
final class ProfileNeedsChanges extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly RejectReason $reason,
        public readonly ?string $note,
        public readonly WizardStep $step = WizardStep::Basic,
    ) {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Your Oppam Matrimony profile needs a few changes'))
            ->line($this->reason->memberMessage());

        if (filled($this->note)) {
            $mail->line(__('Reviewer’s note: :note', ['note' => $this->note]));
        }

        return $mail->action(__('Edit & resubmit'), route('member.onboarding', ['step' => $this->step->value]));
    }
}
