<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * §11A B.15 "KycDecision" to the bureau Owner (SMS / email per M08's `account_status`, mandatory):
 * approved — the bureau is active; rejected — the reason and how to resend; application rejected.
 * The live banner on `broker-owner.{broker}` comes with the broker channels (P7.2+).
 */
final class BrokerKycDecided extends MemberNotification
{
    public const APPROVED = 'approved';

    public const KYC_REJECTED = 'kyc_rejected';

    public const APPLICATION_REJECTED = 'application_rejected';

    public function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return match ($this->outcome) {
            self::APPROVED => new NotificationContentData(
                title: __('Your bureau is verified'),
                body: __('Your KYC has been approved. You can now create, import and export client profiles.'),
                url: route('broker.dashboard'),
            ),
            self::KYC_REJECTED => new NotificationContentData(
                title: __('Please check your KYC details'),
                body: __('We couldn’t verify your KYC: :reason Please correct it and send it again.', ['reason' => (string) $this->reason]),
                url: route('broker.kyc'),
            ),
            default => new NotificationContentData(
                title: __('Your bureau application was not approved'),
                body: __('Reason: :reason Contact our support team if you have questions.', ['reason' => (string) $this->reason]),
                url: route('login'),
            ),
        };
    }

    /** Bureau logins have no member notification settings page: no preferences link. */
    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return (new MailMessage)->subject($content->title.' – '.config('oppam.site.name'))
            ->line($content->body)
            ->action(__('Open'), $content->url);
    }
}
