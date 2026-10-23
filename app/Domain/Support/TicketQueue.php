<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Events\Admin\AdminQueueCountChanged;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

/** The support desk's live waiting count (`admin.queues`, `support.tickets`), sent after the transaction commits. */
final class TicketQueue
{
    public function announce(): void
    {
        DB::afterCommit(static function (): void {
            rescue(static fn () => event(new AdminQueueCountChanged(SupportTicket::QUEUE_KEY, SupportTicket::waitingCount())), report: true);
        });
    }
}
