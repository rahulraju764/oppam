<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A bureau's note on a client (B.6 notes & reminders).
 */
enum ClientNoteType: string
{
    case Note = 'NOTE';
    case Call = 'CALL';
    case Meeting = 'MEETING';

    public function label(): string
    {
        return match ($this) {
            self::Note => __('Note'),
            self::Call => __('Call'),
            self::Meeting => __('Meeting'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
