<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Facades\Settings;

/**
 * Support ticket categories (PRD A10 "categories with SLAs", decisions 2026-10-23). The SLA is the
 * time to the first reply: safety `support.sla_safety_hours` (4), payments
 * `support.sla_payments_hours` (24), everything else `support.sla_default_hours` (48).
 */
enum TicketCategory: string
{
    case Account = 'ACCOUNT';
    case Profile = 'PROFILE';
    case Payments = 'PAYMENTS';
    case Safety = 'SAFETY';
    case Technical = 'TECHNICAL';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Account => __('Account & login'),
            self::Profile => __('My profile'),
            self::Payments => __('Payments & membership'),
            self::Safety => __('Safety concern'),
            self::Technical => __('Something isn’t working'),
            self::Other => __('Something else'),
        };
    }

    public function slaHours(): int
    {
        return Settings::int(match ($this) {
            self::Safety => SettingKey::SupportSlaSafetyHours,
            self::Payments => SettingKey::SupportSlaPaymentsHours,
            default => SettingKey::SupportSlaDefaultHours,
        });
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
