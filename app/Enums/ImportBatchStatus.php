<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A bulk-import batch (B.9).
 */
enum ImportBatchStatus: string
{
    case Uploaded = 'UPLOADED';
    case Mapping = 'MAPPING';        // P7.6: required columns not recognised — the bureau maps the headers
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
            self::Mapping => __('Needs column matching'),
            self::Validating => __('Validating'),
            self::Ready => __('Ready'),
            self::Importing => __('Importing'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** <x-ui.badge> variant (explicit map — never built from data). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Ready, self::Completed => 'success',
            self::Failed => 'danger',
            self::Mapping => 'warning',
            default => 'muted',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
