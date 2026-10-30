<?php

declare(strict_types=1);

namespace App\Enums;

/** How a client's "I never agreed to this profile" complaint reached Oppam (§11A B.12, R-A07-10). */
enum DisputeSource: string
{
    case Call = 'CALL';
    case Email = 'EMAIL';
    case SupportTicket = 'SUPPORT_TICKET';
    case Report = 'REPORT';

    public function label(): string
    {
        return match ($this) {
            self::Call => __('Phone call'),
            self::Email => __('Email'),
            self::SupportTicket => __('Support ticket'),
            self::Report => __('Profile report'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
