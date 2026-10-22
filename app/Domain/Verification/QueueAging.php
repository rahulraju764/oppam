<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Enums\SettingKey;
use App\Support\Facades\Settings;
use Carbon\CarbonInterface;

/**
 * A05 aging colours: a request waiting less than `verification.aging_warn_hours` (48) is fresh,
 * up to `verification.aging_breach_hours` (72) amber, then red. One place for the queue rows, the
 * A02 card and the sidebar badge.
 */
final class QueueAging
{
    public const FRESH = 'fresh';

    public const WARN = 'warn';

    public const BREACH = 'breach';

    /** @return self::FRESH|self::WARN|self::BREACH */
    public function level(CarbonInterface $submittedAt): string
    {
        $hours = $submittedAt->diffInHours(now(), true);

        return match (true) {
            $hours >= Settings::int(SettingKey::VerificationAgingBreachHours) => self::BREACH,
            $hours >= Settings::int(SettingKey::VerificationAgingWarnHours) => self::WARN,
            default => self::FRESH,
        };
    }

    /** @return 'success'|'warning'|'danger' an <x-ui.badge> variant */
    public function variant(string $level): string
    {
        return match ($level) {
            self::BREACH => 'danger',
            self::WARN => 'warning',
            default => 'success',
        };
    }

    /** The visible label (colour is never the only signal). */
    public function label(string $level): string
    {
        return match ($level) {
            self::BREACH => __('Over :h h', ['h' => Settings::int(SettingKey::VerificationAgingBreachHours)]),
            self::WARN => __('Over :h h', ['h' => Settings::int(SettingKey::VerificationAgingWarnHours)]),
            default => __('On time'),
        };
    }
}
