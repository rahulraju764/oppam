<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Enums\MessageType;
use Illuminate\Support\Str;

/**
 * What a message says on screen (PRD M07): a SYSTEM message is stored in English and translated
 * when shown; a message unsent for everyone reads "This message was deleted" (P4.2). The inbox
 * preview is the same text, shortened.
 */
final class MessageText
{
    public const PREVIEW_LENGTH = 80;

    public static function display(MessageType $type, ?string $body, bool $deleted = false): string
    {
        if ($deleted) {
            return __('This message was deleted');
        }

        return $type === MessageType::System ? __((string) $body) : (string) $body;
    }

    public static function preview(MessageType $type, ?string $body, bool $deleted = false): string
    {
        return Str::limit(Str::squish(self::display($type, $body, $deleted)), self::PREVIEW_LENGTH);
    }
}
