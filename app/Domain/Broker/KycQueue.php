<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Enums\KycSubmissionStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Models\Broker;
use App\Models\BrokerKyc;
use Illuminate\Support\Facades\DB;

/**
 * The A07 "Applications & KYC" queue's waiting count (KYC submissions to review + applications
 * whose Owner proved the mobile but sent no KYC yet), kept live on `admin.queues` after the surrounding transaction commits
 * (best-effort, as the other queues).
 */
final class KycQueue
{
    public const QUEUE_KEY = 'broker_kyc';

    public static function waitingCount(): int
    {
        return BrokerKyc::query()->where('status', KycSubmissionStatus::Pending)->count()
            + Broker::query()->applicationsToReview()->count();
    }

    public function announce(): void
    {
        DB::afterCommit(static function (): void {
            rescue(static fn () => event(new AdminQueueCountChanged(self::QUEUE_KEY, self::waitingCount())), report: true);
        });
    }
}
