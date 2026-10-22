<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Admin\ModerationClaimChanged;
use App\Models\VerificationRequest;
use App\Support\Realtime\LiveSignal;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the A05 queue live for officers (F05 "A05 queue (+1 live)", PRD §9.4): after the
 * surrounding transaction commits, the waiting count goes out on `admin.queues` (sidebar badge,
 * A02 card), and a claim taken or released tells open queue pages to re-read ("being reviewed by
 * …"). Best-effort — the database is the truth.
 */
final class VerificationQueue
{
    public function announceCount(): void
    {
        DB::afterCommit(static function (): void {
            rescue(static fn () => event(new AdminQueueCountChanged(VerificationRequest::QUEUE_KEY, VerificationRequest::waitingCount())), report: true);
        });
    }

    public function announceClaim(): void
    {
        LiveSignal::afterCommit(new ModerationClaimChanged(VerificationRequest::QUEUE_KEY));
    }
}
