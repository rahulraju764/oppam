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

        foreach ($this->words() as $word) {
            if (preg_match($this->pattern($word), $lower) === 1) {
                return $word;
            }
        }

        return null;
    }

    /**
     * Hide every listed word in the text (A13 rules editor: abusive words set to "hide and flag").
     *
     * @return array{0: string, 1: list<string>} the text with each word replaced by the mask, and the words found
     */
    public function mask(string $text, string $mask): array
    {
        $found = [];

        foreach ($this->words() as $word) {
            $count = 0;
            $text = (string) preg_replace($this->pattern($word).'i', $mask, $text, -1, $count);
            if ($count > 0) {
                $found[] = $word;
            }
        }

        return [$text, $found];
    }

    /** @return list<string> active words of every A11 list */
    private function words(): array
    {
        $words = [];
        foreach (array_keys(MasterLists::WORD_LISTS) as $group) {
            foreach ($this->masters->options($group) as $item) {
                $words[] = $item->label;
            }
        }

        return $words;
    }

    /** Whole word only, Unicode-aware (add "i" for a case-insensitive match on original text). */
    private function pattern(string $word): string
    {
        return '/(?<![\p{L}\p{M}])'.preg_quote(mb_strtolower($word), '/').'(?![\p{L}\p{M}])/u';
    }
}
