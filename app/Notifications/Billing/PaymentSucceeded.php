<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A payment went through and the plan is active (M08 `payment_success`, mandatory — F04 "payment_success
 * notification + invoice email"). In-app + live, SMS on its DLT template, and email under the M08
 * rules (only to a verified address — decisions 2026-10-06, so not yet). The invoice is downloaded
 * from the success page; the email links there rather than attaching the PDF.
 */
final class PaymentSucceeded extends MemberNotification
{
    public function __construct(
        public readonly string $orderCode,
        public readonly string $planName,
        public readonly CarbonInterface $endsAt,
        public readonly string $invoiceNumber,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::PaymentSuccess;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Payment received — welcome to :plan', ['plan' => $this->planName]),
            body: __('Your :plan membership is active until :date. Invoice :number is ready to download.', [
                'plan' => $this->planName,
                'date' => $this->until(),
                'number' => $this->invoiceNumber,
            ]),
            url: route('member.billing.success', ['order' => $this->orderCode]),
        );
    }

    /** @return list<string> DLT variables: plan name, end date */
    public function smsVariables(object $notifiable): array
    {
        return [$this->planName, $this->until()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return (new MailMessage)
            ->subject($content->title)
            ->line($content->body)
            ->action(__('View payment and invoice'), $content->url);
    }

    private function until(): string
    {
        return $this->endsAt->copy()->timezone((string) config('oppam.display_timezone'))->format('j M Y');
    }
}
