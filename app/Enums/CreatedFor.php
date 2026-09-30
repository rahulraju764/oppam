<?php

declare(strict_types=1);

namespace App\Enums;

/** Who the profile is for — the "Profile for" field on registration (M01, PRD §7.2). */
enum CreatedFor: string
{
    case Self = 'SELF';
    case Son = 'SON';
    case Daughter = 'DAUGHTER';
    case Brother = 'BROTHER';
    case Sister = 'SISTER';
    case Relative = 'RELATIVE';
    case Friend = 'FRIEND';

    public function label(): string
    {
        return match ($this) {
            self::Self => __('Myself'),
            self::Son => __('Son'),
            self::Daughter => __('Daughter'),
            self::Brother => __('Brother'),
            self::Sister => __('Sister'),
            self::Relative => __('Relative'),
            self::Friend => __('Friend'),
        };
    }

    /** M01: gender is auto-derived for Son / Daughter / Brother / Sister; null = the member chooses. */
    public function derivedGender(): ?Gender
    {
        return match ($this) {
            self::Son, self::Brother => Gender::Male,
            self::Daughter, self::Sister => Gender::Female,
            default => null,
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
