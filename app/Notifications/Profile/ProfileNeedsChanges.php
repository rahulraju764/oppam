<?php

declare(strict_types=1);

namespace App\Notifications\Profile;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Enums\RejectReason;
use App\Enums\WizardStep;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your profile needs changes" (A04 reject / request changes, R-M02-5): the category's member
 * message, the moderator's note, and a link to the wizard step to fix (M08 `profile_rejected`,
 * mandatory: in-app, live, email, SMS).
 */
final class ProfileNeedsChanges extends MemberNotification
{
    public function __construct(
        public readonly RejectReason $reason,
        public readonly ?string $note,
        public readonly WizardStep $step = WizardStep::Basic,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ProfileRejected;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your profile needs a few changes'),
            body: $this->reason->memberMessage(),
            url: route('member.onboarding', ['step' => $this->step->value]),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Your Oppam Matrimony profile needs a few changes'))
            ->line($this->reason->memberMessage());

        if (filled($this->note)) {
            $mail->line(__('Reviewer’s note: :note', ['note' => $this->note]));
        }

        return $this->withPreferencesLink($mail->action(__('Edit & resubmit'), route('member.onboarding', ['step' => $this->step->value])));
    }
}
