<?php

declare(strict_types=1);

namespace App\Domain\Engagement;

use App\Enums\EngagementSignalStatus;
use App\Enums\EngagementSignalType;
use App\Events\Admin\AdminQueueCountChanged;
use App\Models\EngagementSignal;
use Illuminate\Support\Facades\DB;

/**
 * Raises A13 abuse signals (PRD A13 "Engagement abuse monitors") — the one place that writes
 * engagement_signals rows. A member has at most one OPEN signal of a type: a repeat while it waits
 * counts `occurrences` and refreshes the details instead of a new row, so the moderator's list
 * isn't flooded. The "Abuse signals" badge follows live after the surrounding transaction commits
 * (AdminQueueCountChanged on admin.queues, best-effort). Details are counts and windows only.
 */
final class EngagementSignals
{
    /** @param  array<string, int|string>  $details */
    public function raise(string $profileId, EngagementSignalType $type, array $details): void
    {
        $open = EngagementSignal::query()->where('profile_id', $profileId)->where('type', $type->value)->open()->first();

        if ($open !== null) {
            $open->occurrences++;
            $open->details = $details;
            $open->last_raised_at = now();
            $open->save();

            return;
        }

        $signal = new EngagementSignal;
        $signal->profile_id = $profileId;
        $signal->type = $type;
        $signal->details = $details;
        $signal->status = EngagementSignalStatus::Open;
        $signal->occurrences = 1;
        $signal->last_raised_at = now();
        $signal->save();

        $this->announce();
    }

    /** Send the waiting count to the admin badges once the surrounding transaction commits. */
    public function announce(): void
    {
        DB::afterCommit(static function (): void {
            rescue(static fn () => event(new AdminQueueCountChanged(EngagementSignal::QUEUE_KEY, EngagementSignal::openCount())), report: true);
        });
    }
}
