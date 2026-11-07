<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The versioned legal pages (A08, M12, PRD §14). Terms and privacy are agreed to at sign-up and
 * again after a material change; the grievance page (IT Rules 2021) is information only.
 */
enum LegalKind: string
{
    case Terms = 'TERMS';
    case Privacy = 'PRIVACY';
    case Grievance = 'GRIEVANCE';

    public function label(): string
    {
        return match ($this) {
            self::Terms => __('Terms of Use'),
            self::Privacy => __('Privacy Policy'),
            self::Grievance => __('Grievance Redressal'),
        };
    }

    public function route(): string
    {
        return match ($this) {
            self::Terms => 'terms',
            self::Privacy => 'privacy',
            self::Grievance => 'grievance',
        };
    }

    public function needsAcceptance(): bool
    {
        return $this !== self::Grievance;
    }

    /** @return list<self> */
    public static function accepted(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind): bool => $kind->needsAcceptance()));
    }
}
