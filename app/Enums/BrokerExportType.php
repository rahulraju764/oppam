<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a bureau exported (B.7, append-only log).
 */
enum BrokerExportType: string
{
    case BiodataPdf = 'BIODATA_PDF';
    case PhotoPackage = 'PHOTO_PACKAGE';

    public function label(): string
    {
        return match ($this) {
            self::BiodataPdf => __('Biodata PDF'),
            self::PhotoPackage => __('Photo package'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
