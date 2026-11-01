<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §11A B.23 payout-run reminder by email: sent to every active admin holding `brokers.payout`.
 * Names the month and links to the payout runs — never a bureau or an amount.
 */
final class PayoutRunDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $month)
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
            ->subject(__('Broker payout run due for :month', ['month' => $this->month]))
            ->line(__('Bureaus have earned commission for :month and no payout run covers it yet.', ['month' => $this->month]))
            ->action(__('Open payout runs'), route('admin.brokers.payouts'))
            ->line(__('You get this email because you can manage broker payouts.'));
    }
}
