<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use App\Enums\SystemAlertKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A12 (P9.2b2): one email when an alert OPENS, to active admins with `system.alerts` — never again
 * while it stays open. The kind and its numbers only, never member data.
 */
final class SystemAlertOpened extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SystemAlertKind $kind,
        public readonly int $value,
        public readonly int $threshold,
    ) {
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
            ->error()
            ->subject(__('Oppam alert: :kind', ['kind' => $this->kind->label()]))
            ->line($this->kind->describe($this->value, $this->threshold))
            ->line(__('You get one email when an alert opens; it closes by itself once the condition has been clear for a few minutes.'))
            ->action(__('Open system health'), route('admin.system.health'))
            ->line(__('You get this email because you can receive system alerts.'));
    }
}
