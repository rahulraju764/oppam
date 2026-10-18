<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a chat message is in the A13 flagged queue (PRD A13, M07 send flow). The first five are
 * raised by ContentSafety as the message is sent; REPORTED by a member reporting it from chat.
 */
enum MessageFlagRule: string
{
    case ContactInfo = 'CONTACT_INFO';     // phone / email / social handle in the thread's first messages (masked)
    case ExternalLink = 'LINK';            // a web address (masked in the first messages, flagged after)
    case MoneyRequest = 'MONEY';           // UPI id, bank / IFSC details, "send money" phrases
    case Profanity = 'PROFANITY';          // a word from the A11 EN / ML / Manglish lists
    case Repetition = 'REPETITION';        // the same text sent into many threads (spam)
    case Reported = 'REPORTED';            // a member reported this message

    public function label(): string
    {
        return match ($this) {
            self::ContactInfo => __('Contact details'),
            self::ExternalLink => __('External link'),
            self::MoneyRequest => __('Money / UPI / bank request'),
            self::Profanity => __('Abusive language'),
            self::Repetition => __('Repeated message (spam)'),
            self::Reported => __('Reported by a member'),
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
