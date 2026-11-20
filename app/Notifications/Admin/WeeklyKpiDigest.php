<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A09 weekly KPI digest (P9.1b), by email to an admin with `reports.view`: last week's headline
 * numbers against the week before, each linking to its report. Numbers only — no member or admin
 * names, no contact details (built by KpiDigest).
 */
final class WeeklyKpiDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{label: string, value: string, delta: string|null, report: string}> $lines */
    public function __construct(
        public readonly string $week,
        public readonly array $lines,
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
        $mail = (new MailMessage)
            ->subject(__(':site — weekly numbers, :week', ['site' => config('oppam.site.name'), 'week' => $this->week]))
            ->line(__('Last week (:week) against the week before:', ['week' => $this->week]));

        foreach ($this->lines as $line) {
            $mail->line('• '.$line['label'].': **'.$line['value'].'**'.($line['delta'] !== null ? ' ('.$line['delta'].')' : ''));
        }

        return $mail->action(__('Open the reports'), route('admin.reports.index'))
            ->line(__('You get this email because you can view reports. It can be switched off in Settings → Business rules.'));
    }
}
