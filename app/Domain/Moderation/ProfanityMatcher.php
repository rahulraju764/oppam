<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Domain\Masters\MasterLists;
use App\Services\Masters\Masters;

/**
 * Finds an abusive word from the A11 word lists (EN, Malayalam, Manglish — active words only,
 * cached by Masters and flushed on every edit) in a text. Whole words only, Unicode-aware, so
 * "Scunthorpe"-style substrings don't trip it. The one place for this rule: A04 profile pre-flags
 * (ModerationFlags) and chat (ContentSafety) both ask here.
 */
final class ProfanityMatcher
{
    public function __construct(private readonly Masters $masters) {}

    /** The first listed word found in the text, or null. */
    public function firstMatch(string $text): ?string
    {
        $lower = mb_strtolower($text);

        foreach (array_keys(MasterLists::WORD_LISTS) as $group) {
            foreach (array_map(fn ($item): string => $item->label, $this->masters->options($group)) as $word) {
                if (preg_match('/(?<![\p{L}\p{M}])'.preg_quote(mb_strtolower($word), '/').'(?![\p{L}\p{M}])/u', $lower) === 1) {
                    return $word;
                }
            }
        }

        return null;
    }
}
