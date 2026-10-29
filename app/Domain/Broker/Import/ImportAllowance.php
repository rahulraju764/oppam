<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Enums\ImportRowStatus;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\SettingKey;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * How many rows a bureau may still import today (§11A B.9 limits, R-M13-31; decisions 2026-10-28):
 *  - the daily cap is the lower of the bureau's own `import_daily_cap` (A07) and the global
 *    `broker.import_rows_per_day` (A15), so lowering either takes effect;
 *  - a NEW bureau gets `broker.import_rows_per_day_new` (100) instead: under 30 days since its KYC
 *    approval, or fewer than 20 profiles decided at A04, or under 80 % of them approved — the full
 *    cap starts once it is past all three;
 *  - "today" is the IST calendar day; every row imported today counts, whatever became of its
 *    profile since (claimed, deleted).
 */
final class ImportAllowance
{
    public const NEW_BUREAU_DAYS = 30;

    public const NEW_BUREAU_MIN_MODERATED = 20;

    public const NEW_BUREAU_MIN_APPROVAL_PCT = 80;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function remainingToday(Broker $broker): int
    {
        return max(0, $this->dailyCap($broker) - $this->importedToday($broker));
    }

    public function dailyCap(Broker $broker): int
    {
        if ($this->isNew($broker)) {
            return $this->settings->int(SettingKey::BrokerImportRowsPerDayNew);
        }

        return min($broker->import_daily_cap, $this->settings->int(SettingKey::BrokerImportRowsPerDay));
    }

    public function isNew(Broker $broker): bool
    {
        if ($broker->activated_at === null || $broker->activated_at->gt(now()->subDays(self::NEW_BUREAU_DAYS))) {
            return true;
        }

        $decided = ModerationItem::query()
            ->where('type', ModerationItemType::ProfileNew)
            ->whereIn('status', [ModerationStatus::Approved, ModerationStatus::Rejected])
            ->whereIn('profile_id', Profile::withTrashed()->select('id')->where('managed_by_broker_id', $broker->id))
            ->selectRaw('COUNT(*) AS decided, SUM(status = ?) AS approved', [ModerationStatus::Approved->value])
            ->toBase()
            ->first();

        $total = (int) ($decided->decided ?? 0);
        $approved = (int) ($decided->approved ?? 0);

        return $total < self::NEW_BUREAU_MIN_MODERATED || $approved * 100 < $total * self::NEW_BUREAU_MIN_APPROVAL_PCT;
    }

    public function importedToday(Broker $broker): int
    {
        $start = Carbon::now('Asia/Kolkata')->startOfDay()->utc();

        // Import rows, not profiles: a profile claimed or deleted the same day still used the allowance.
        return BrokerImportRow::query()
            ->whereIn('batch_id', BrokerImportBatch::query()->select('id')->where('broker_id', $broker->id))
            ->where('status', ImportRowStatus::Imported)
            ->where('updated_at', '>=', $start)
            ->count();
    }
}
