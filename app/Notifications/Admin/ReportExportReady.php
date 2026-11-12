<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use App\Enums\ReportKey;
use App\Models\AdminExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A09 (P9.1a): "your report export is ready" — to the admin who asked. The link opens the
 * Reports → Exports page behind the admin sign-in; the file itself never travels by email and the
 * mail carries no download token.
 */
final class ReportExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $report;

    public readonly string $code;

    public function __construct(AdminExport $export)
    {
        $this->report = ReportKey::tryFrom($export->report)?->label() ?? $export->report;
        $this->code = $export->code;
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
            ->subject(__('Your :report export is ready', ['report' => $this->report]))
            ->line(__('The :report report export you asked for (:code) is ready. It can be downloaded for 24 hours.', ['report' => $this->report, 'code' => $this->code]))
            ->action(__('Open report exports'), route('admin.reports.exports'))
            ->line(__('Sign in to the admin panel to download it. The file is never attached to an email.'));
    }
}
