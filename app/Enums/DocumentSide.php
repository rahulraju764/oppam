<?php

declare(strict_types=1);

namespace App\Enums;

/** Which side of an ID document a file is (M09 "front/back upload"). Lower-case in viewer URLs. */
enum DocumentSide: string
{
    case Front = 'FRONT';
    case Back = 'BACK';

    public function label(): string
    {
        return match ($this) {
            self::Front => __('Front'),
            self::Back => __('Back'),
        };
    }

    public function slug(): string
    {
        return strtolower($this->value);
    }

    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom(strtoupper($slug));
    }
}
