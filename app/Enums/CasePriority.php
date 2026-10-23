<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Facades\Settings;

/**
 * Triage of an A10 abuse case (PRD A10 "auto-triaged P0/P1/P2 with SLAs", decisions 2026-10-23):
 * P0 = asking for money or auto-escalated (`safety.p0_sla_hours`, 4), P1 = abuse, photos, fake
 * profile (`safety.p1_sla_hours`, 24), P2 = the rest (`safety.p2_sla_hours`, 72). A case takes the
 * most urgent priority of its reports; the queue sorts by rank, then by SLA due time.
 */
enum CasePriority: string
{
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';

    public function label(): string
    {
        return match ($this) {
            self::P0 => __('P0 — urgent'),
            self::P1 => __('P1 — high'),
            self::P2 => __('P2 — normal'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::P0 => 'danger',
            self::P1 => 'warning',
            self::P2 => 'muted',
        };
    }

    /** 0 is the most urgent. */
    public function rank(): int
    {
        return match ($this) {
            self::P0 => 0,
            self::P1 => 1,
            self::P2 => 2,
        };
    }

    public function isMoreUrgentThan(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    public function slaHours(): int
    {
        return Settings::int(match ($this) {
            self::P0 => SettingKey::SafetyP0SlaHours,
            self::P1 => SettingKey::SafetyP1SlaHours,
            self::P2 => SettingKey::SafetyP2SlaHours,
        });
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
