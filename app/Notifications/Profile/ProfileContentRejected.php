<?php

declare(strict_types=1);

namespace App\Notifications\Profile;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Enums\RejectReason;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A photo, caption or edited text was not approved (A04 photo / edited-fields queues). The profile
 * itself stays live (M08 `profile_rejected`, mandatory).
 */
final class ProfileContentRejected extends MemberNotification
{
    public function __construct(
        public readonly string $what,
        public readonly RejectReason $reason,
        public readonly ?string $note,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ProfileRejected;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your :what was not approved', ['what' => $this->what]),
            body: $this->reason->memberMessage(),
            url: route('member.profile.me'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('An update to your profile was not approved'))
            ->line(__('Your :what was not approved.', ['what' => $this->what]))
            ->line($this->reason->memberMessage());

        if (filled($this->note)) {
            $mail->line(__('Reviewer’s note: :note', ['note' => $this->note]));
        }

        return $this->withPreferencesLink($mail->action(__('View my profile'), route('member.profile.me')));
    }
}
