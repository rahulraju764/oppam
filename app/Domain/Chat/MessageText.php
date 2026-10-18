<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Enums\MessageType;
use Illuminate\Support\Str;

/**
 * What a message says on screen (PRD M07): a SYSTEM message is stored in English and translated
 * when shown; an IMAGE message reads "Photo" in lists and notices; a message unsent for everyone
 * reads "This message was deleted"; one a moderator deleted (A13) reads "This message was removed by
 * Oppam". The inbox preview is the same text, shortened.
 */
final class MessageText
{
    public const PREVIEW_LENGTH = 80;

    public static function display(MessageType $type, ?string $body, bool $deleted = false, bool $removed = false): string
    {
        return match (true) {
            $removed => __('This message was removed by Oppam'),
            $deleted => __('This message was deleted'),
            $type === MessageType::System => __((string) $body),
            $type === MessageType::Image => __('Photo'),
            default => (string) $body,
        };
    }

    public static function preview(MessageType $type, ?string $body, bool $deleted = false, bool $removed = false): string
    {
        $text = Str::limit(Str::squish(self::display($type, $body, $deleted, $removed)), self::PREVIEW_LENGTH);

        return $type === MessageType::Image && ! $deleted && ! $removed ? '📷 '.$text : $text;
    }
}
