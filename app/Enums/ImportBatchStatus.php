<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A bulk-import batch (B.9).
 */
enum ImportBatchStatus: string
{
    case Uploaded = 'UPLOADED';
    case Validating = 'VALIDATING';
    case Ready = 'READY';
    case Importing = 'IMPORTING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => __('Uploaded'),
            self::Validating => __('Validating'),
            self::Ready => __('Ready'),
            self::Importing => __('Importing'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
