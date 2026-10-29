<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Actions\Broker\Safety\PauseBrokerImports;
use App\Enums\ImportsPauseReason;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\SettingKey;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * A04's verdict on one batch (§11A B.9 batch history "moderation approval rate", auto-pause R-M13-32).
 * Recomputed from the moderation queue — never counted up — so a resubmission or a repeated call
 * gives the same numbers: submitted = the batch's profiles that reached A04, approved / rejected =
 * each one's LATEST decision (a profile rejected, fixed and approved counts as approved; "changes
 * requested" is not a rejection).
 *
 * Auto-pause: once at least `broker.import_auto_pause_min_decided` (5) of them are decided and MORE
 * than `broker.import_auto_pause_rejection_pct` (40 %) of the submitted ones are rejected, the
 * bureau's imports pause (decisions 2026-10-28). Single-profile creation is not affected (R-A07-9).
 */
final class BatchModerationTally
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PauseBrokerImports $pause,
    ) {}

    public function refresh(BrokerImportBatch $batch): void
    {
        $profiles = Profile::withTrashed()->select('id')->where('import_batch_id', $batch->id);

        $latest = [];
        ModerationItem::query()
            ->where('type', ModerationItemType::ProfileNew)
            ->whereIn('profile_id', $profiles)
            ->orderBy('submitted_at')->orderBy('id')
            ->get(['profile_id', 'status'])
            ->each(function (ModerationItem $item) use (&$latest): void {
                $latest[$item->profile_id] = $item->status;
            });

        $counts = ['submitted' => count($latest), 'approved' => 0, 'rejected' => 0];
        foreach ($latest as $status) {
            if ($status === ModerationStatus::Approved) {
                $counts['approved']++;
            } elseif ($status === ModerationStatus::Rejected) {
                $counts['rejected']++;
            }
        }

        DB::transaction(function () use ($batch, $counts): void {
            BrokerImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
            $batch->forceFill([
                'rows_submitted' => $counts['submitted'],
                'rows_approved' => $counts['approved'],
                'rows_rejected' => $counts['rejected'],
            ])->save();
        });

        if ($this->tripsAutoPause($counts['approved'] + $counts['rejected'], $counts['rejected'], $counts['submitted'])) {
            /** @var Broker $broker */
            $broker = $batch->broker()->firstOrFail();
            $this->pause->handle($broker, ImportsPauseReason::Auto, $batch);
        }
    }

    public function tripsAutoPause(int $decided, int $rejected, int $submitted): bool
    {
        return $submitted > 0
            && $decided >= $this->settings->int(SettingKey::BrokerImportAutoPauseMinDecided)
            && $rejected * 100 > $submitted * $this->settings->int(SettingKey::BrokerImportAutoPauseRejectionPct);
    }
}
