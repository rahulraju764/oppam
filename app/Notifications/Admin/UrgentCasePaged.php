<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use App\Enums\CasePriority;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The P0 page by email (PRD A10): sent to every active admin holding `safety.reports.act`. Names
 * the case code and links to it — never the member, the reporters or what was reported.
 */
final class UrgentCasePaged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $caseCode)
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
            ->subject(__('Urgent safety case :code', ['code' => $this->caseCode]))
            ->line(__('A member report needs attention within :hours hours (P0).', ['hours' => CasePriority::P0->slaHours()]))
            ->action(__('Open the case'), route('admin.safety.cases.show', ['case' => $this->caseCode]))
            ->line(__('You get this email because you can act on safety reports.'));
    }
}
