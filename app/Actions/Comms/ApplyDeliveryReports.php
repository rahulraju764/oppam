<?php

declare(strict_types=1);

namespace App\Actions\Comms;

use App\Data\Comms\MailEventReport;
use App\Enums\CommsChannel;
use App\Enums\MailEventKind;
use App\Enums\OutboundStatus;
use App\Models\OutboundMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * Provider reports → the A14 delivery log (P8.3a), called by the webhook controllers after the
 * request was verified. Matched by provider id and channel only; an unknown id is ignored (it may
 * be another app's, or pruned). Idempotent: a report only moves a row forward — SENT → DELIVERED /
 * FAILED, anything → BOUNCED — so a replayed or late report changes nothing.
 */
final class ApplyDeliveryReports
{
    /** MSG91 delivery-report status codes: 1 delivered; failures below; others (8 awaited…) are left alone. */
    private const MSG91_FAILED = ['2', '9', '16', '17', '25', '26'];

    /**
     * @param  list<array{request_id: string, status: string}>  $reports
     * @return int rows changed
     */
    public function sms(array $reports): int
    {
        $changed = 0;
        foreach ($reports as $report) {
            $status = $report['status'] === '1' ? OutboundStatus::Delivered
                : (in_array($report['status'], self::MSG91_FAILED, true) ? OutboundStatus::Failed : null);
            if ($status === null) {
                continue;
            }

            $changed += OutboundMessage::query()
                ->where('channel', CommsChannel::Sms->value)
                ->where('provider_id', mb_substr($report['request_id'], 0, 100))
                ->where('status', OutboundStatus::Sent->value)
                ->update([
                    'status' => $status->value,
                    $status === OutboundStatus::Delivered ? 'delivered_at' : 'failed_at' => now(),
                    'error_message' => $status === OutboundStatus::Failed ? 'MSG91 report status '.mb_substr($report['status'], 0, 4) : null,
                    'updated_at' => now(),
                ]);
        }

        return $changed;
    }

    /**
     * Mail-provider events. Bounced: anything → BOUNCED. Delivered: SENT → DELIVERED only. Opened:
     * stamps opened_at once (the status is unchanged; an open after a bounce report is ignored).
     *
     * @param  list<MailEventReport>  $events
     * @return int rows changed
     */
    public function mailEvents(array $events): int
    {
        $changed = 0;
        foreach ($events as $event) {
            $rows = OutboundMessage::query()
                ->where('channel', CommsChannel::Email->value)
                ->where('provider_id', mb_substr($event->providerMessageId, 0, 100));

            $changed += match ($event->kind) {
                MailEventKind::Bounced => $this->bounce($rows, $event),
                MailEventKind::Delivered => $rows->where('status', OutboundStatus::Sent->value)
                    ->update(['status' => OutboundStatus::Delivered->value, 'delivered_at' => now(), 'updated_at' => now()]),
                MailEventKind::Opened => $rows->whereNull('opened_at')->where('status', '!=', OutboundStatus::Bounced->value)
                    ->update(['opened_at' => now(), 'updated_at' => now()]),
            };
        }

        return $changed;
    }

    /** @param Builder<OutboundMessage> $rows */
    private function bounce(Builder $rows, MailEventReport $bounce): int
    {
        return $rows
            ->where('status', '!=', OutboundStatus::Bounced->value)
            ->update([
                'status' => OutboundStatus::Bounced->value,
                'bounced_at' => now(),
                'error_message' => $bounce->permanent ? 'Hard bounce' : 'Soft bounce',
                'updated_at' => now(),
            ]);
    }
}
