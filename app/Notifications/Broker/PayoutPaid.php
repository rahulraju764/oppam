<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * §11A B.15 "payout paid" to the bureau Owner (in-app + email): the run code and the net amount —
 * the UTR and bank details only on the statement behind the Earnings page.
 */
final class PayoutPaid extends MemberNotification
{
    public function __construct(
        public readonly string $runCode,
        public readonly string $amount,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::PayoutPaid;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Payout sent: :amount', ['amount' => $this->amount]),
            body: __('Your commission payout :run has been sent to your bank account. The statement is on your Earnings page.', ['run' => $this->runCode]),
            url: route('broker.earnings'),
        );
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
