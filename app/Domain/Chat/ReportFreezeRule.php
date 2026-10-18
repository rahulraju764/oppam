<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Models\Report;
use App\Support\Facades\Settings;

/**
 * The automatic freeze after reports (PRD M09, decisions 2026-10-16) — the one place for its rule:
 * a member reported from chat by `chat.auto_freeze_reports` (3) DIFFERENT members within
 * `chat.auto_freeze_window_days` (30), dismissed reports not counting. ReportChatMessages freezes
 * when it is reached; DecideMessageFlag opens the threads again when dismissals take the member
 * back below it.
 */
final class ReportFreezeRule
{
    /** Different members whose chat reports on this profile still count. */
    public function reporters(string $profileId): int
    {
        return Report::query()->where('reported_profile_id', $profileId)
            ->where('source', ReportSource::Chat->value)
            ->where('status', '!=', ReportStatus::Dismissed->value)
            ->where('created_at', '>=', now()->subDays(Settings::int(SettingKey::ChatAutoFreezeWindowDays)))
            ->distinct()->count('reporter_profile_id');
    }

    public function reached(string $profileId): bool
    {
        return $this->reporters($profileId) >= Settings::int(SettingKey::ChatAutoFreezeReports);
    }
}
