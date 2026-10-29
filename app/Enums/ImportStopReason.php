<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the last import run of a batch stopped before every row was imported (decisions 2026-10-28).
 */
enum ImportStopReason: string
{
    case DailyCap = 'DAILY_CAP';              // today's allowance used up — the rest waits (R-M13-31)
    case BureauBlocked = 'BUREAU_BLOCKED';    // bureau inactive, KYC not verified, imports paused or the flag off
    case UploaderRemoved = 'UPLOADER_REMOVED'; // the uploader lost access mid-run — the rest was skipped

    public function message(): string
    {
        return match ($this) {
            self::DailyCap => __('Today’s import allowance is used up. The remaining rows can be imported tomorrow.'),
            self::BureauBlocked => __('Importing stopped because your bureau can’t import right now. The remaining rows were kept; you can cancel the batch.'),
            self::UploaderRemoved => __('Importing stopped because the person who started this import no longer has access. The remaining rows were skipped.'),
        };
    }
}
