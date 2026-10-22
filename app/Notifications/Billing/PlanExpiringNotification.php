<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your plan ends soon" (PRD M10 expiry: reminders at 7 days and 1 day; M08 `plan_expiring`).
 * In-app + SMS by default, email under the M08 rules. Never sent when a renewal is already queued.
 */
final class PlanExpiringNotification extends MemberNotification
{
    public function __construct(
        public readonly string $planName,
        public readonly CarbonInterface $endsAt,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::PlanExpiring;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your :plan plan ends on :date', ['plan' => $this->planName, 'date' => $this->date()]),
            body: __('Renew to keep chatting with your matches and your higher limits. After that date your account moves to the Free plan — nothing you saved is lost.'),
            url: route('member.billing'),
        );
    }

    /** @return list<string> DLT variables: plan name, end date */
    public function smsVariables(object $notifiable): array
    {
        return [$this->planName, $this->date()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return $this->withPreferencesLink((new MailMessage)
            ->subject($content->title)
            ->line($content->body)
            ->action(__('Renew membership'), $content->url));
    }

    private function date(): string
    {
        return $this->endsAt->copy()->timezone((string) config('oppam.display_timezone'))->format('j M Y');
    }
}
