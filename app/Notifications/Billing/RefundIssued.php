<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** A06 "member notified": money is on its way back; says whether the plan ended. Mandatory (R-M08-3). */
final class RefundIssued extends MemberNotification
{
    public function __construct(
        public readonly string $orderCode,
        public readonly string $amount,
        public readonly bool $planEnded,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::RefundIssued;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Refund of ₹:amount on its way', ['amount' => $this->amount]),
            body: $this->planEnded
                ? __('We’ve refunded ₹:amount for order :order, and that membership has ended. It can take a few working days to reach you.', ['amount' => $this->amount, 'order' => $this->orderCode])
                : __('We’ve refunded ₹:amount for order :order. It can take a few working days to reach you.', ['amount' => $this->amount, 'order' => $this->orderCode]),
            url: route('member.billing'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return (new MailMessage)->subject($content->title)->line($content->body)->action(__('View billing'), $content->url);
    }
}
