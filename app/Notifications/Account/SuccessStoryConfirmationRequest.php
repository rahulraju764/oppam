<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your partner shared your wedding story — confirm it?" (PRD M16, P8.2c). Sent to the partner the
 * submitter named. Carries only the story code and the submitter's first name (they are connected);
 * the member confirms or declines in /settings → Hide / delete.
 */
final class SuccessStoryConfirmationRequest extends MemberNotification
{
    public function __construct(
        public readonly string $storyCode,
        public readonly string $submitterFirstName,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Confirm your wedding story'),
            body: __(':name shared your wedding story with Oppam. It is published only if you agree too.', ['name' => $this->submitterFirstName]),
            url: route('member.settings', ['tab' => 'manage']),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreferencesLink(
            (new MailMessage)
                ->subject(__('Confirm your wedding story on Oppam'))
                ->line(__(':name shared your wedding story with Oppam. Congratulations!', ['name' => $this->submitterFirstName]))
                ->line(__('It is published only if you agree too. Sign in to read it and confirm or decline.'))
                ->action(__('Review the story'), route('member.settings', ['tab' => 'manage']))
        );
    }
}
