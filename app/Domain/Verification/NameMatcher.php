<?php

declare(strict_types=1);

namespace App\Domain\Verification;

/**
 * Does the name printed on an ID plausibly belong to this profile (A05 "name-mismatch flag",
 * "Malayali naming-variant guidance")? Kerala names on IDs differ from the profile in harmless
 * ways that must NOT be flagged:
 *  - initials for the house name or father's name ("Anjali K." / "K. Anjali" / "P.V. Thomas");
 *  - a different second word: the father's name in place of the surname ("Anjali Krishnan" for
 *    Anjali Nair), the surname missing (common on Aadhaar), words in another order;
 *  - transliteration (Sree / Sri, Shibu / Sibu, Thomas / Tomas, double letters).
 * Rule: every word (two letters or more) of the profile's FIRST name appears in the document name
 * after phonetic normalising. The surname is left to the officer. A flag only helps — they decide.
 */
final class NameMatcher
{
    public function matches(string $firstName, string $documentName): bool
    {
        $document = array_filter($this->tokens($documentName), fn (string $t): bool => strlen($t) > 1);
        $first = array_filter($this->tokens($firstName), fn (string $t): bool => strlen($t) > 1);

        if ($first === [] || $document === []) {
            return false;
        }

        return array_diff($first, $document) === [];
    }

    /** Normalised full name (the banned-name match): phonetic words, sorted, initials dropped. */
    public function key(string $name): string
    {
        $tokens = array_values(array_filter($this->tokens($name), fn (string $t): bool => strlen($t) > 1));
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** @return list<string> */
    private function tokens(string $name): array
    {
        $name = (string) preg_replace('/[^a-z\s]+/', ' ', strtolower(trim($name)));   // dots, apostrophes, digits → space
        $words = array_filter(preg_split('/\s+/', $name) ?: [], fn (string $t): bool => $t !== '');

        return array_values(array_map(fn (string $t): string => $this->phonetic($t), $words));
    }

    private function phonetic(string $token): string
    {
        $token = strtr($token, ['shree' => 'sri', 'sree' => 'sri', 'ee' => 'i', 'oo' => 'u', 'th' => 't', 'dh' => 'd',
            'sh' => 's', 'kh' => 'k', 'ph' => 'f', 'bh' => 'b', 'gh' => 'g', 'w' => 'v', 'z' => 's', 'y' => 'i']);
        $token = (string) preg_replace('/(.)\1+/', '$1', $token);   // double letters

        return strlen($token) > 2 ? rtrim($token, 'h') : $token;
    }
}
