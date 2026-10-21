<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Data\Chat\SafetyRuleData;

/**
 * Checks a chat safety pattern before the A13 rules editor saves (or dry-runs) it — every chat
 * message runs through it, so a bad pattern must never reach production:
 *  - 1 – MAX_LENGTH characters, and it compiles as "/…/iu" (an unescaped "/" doesn't);
 *  - it doesn't match empty text (it would "find" something in every message);
 *  - it stays fast on hostile input: run with PCRE's JIT off and a low backtrack limit against long
 *    repetitive strings, it must neither fail nor take more than BUDGET_MS — catastrophic
 *    backtracking ("(a+)+$") is refused here rather than discovered on a member's message.
 * Returns a member-of-staff readable problem, or null when the pattern is fine.
 */
final class PatternGuard
{
    public const MAX_LENGTH = 500;

    private const BUDGET_MS = 100;

    private const BACKTRACK_LIMIT = 200000;

    public function problem(string $pattern): ?string
    {
        if (trim($pattern) === '') {
            return __('Enter a pattern.');
        }

        if (mb_strlen($pattern) > self::MAX_LENGTH) {
            return __('The pattern can be at most :max characters.', ['max' => self::MAX_LENGTH]);
        }

        // A pattern ContentSafety already ran in this process sits in PHP's regex cache compiled WITH
        // the JIT, and turning the JIT off then doesn't recompile it (the cached JIT code runs and can
        // fail "JIT stack limit exhausted"). The no-op "S" modifier gives the guard its own cache entry.
        $regex = SafetyRuleData::compile($pattern).'S';

        return $this->withSafeLimits(function () use ($regex): ?string {
            if (@preg_match($regex, '') === false) {
                return __('This pattern is not a valid regular expression (write "/" as "\/").');
            }

            if (preg_match($regex, '') === 1) {
                return __('This pattern matches an empty message — it would match everything.');
            }

            foreach ($this->probes() as $probe) {
                $started = hrtime(true);
                $result = @preg_match_all($regex, $probe);
                $elapsedMs = (hrtime(true) - $started) / 1_000_000;

                if ($result === false || $elapsedMs > self::BUDGET_MS) {
                    return __('This pattern is too slow on long messages (it backtracks too much). Simplify the repeated parts.');
                }
            }

            return null;
        });
    }

    /**
     * Runs the checks with the JIT off (so the backtrack limit applies) and restores the settings.
     *
     * @param  callable(): ?string  $check
     */
    private function withSafeLimits(callable $check): ?string
    {
        $jit = ini_get('pcre.jit');
        $backtrack = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

        try {
            return $check();
        } finally {
            ini_set('pcre.jit', $jit === false ? '1' : $jit);
            ini_set('pcre.backtrack_limit', $backtrack === false ? '1000000' : $backtrack);
        }
    }

    /** @return list<string> long repetitive messages that make a badly nested pattern explode */
    private function probes(): array
    {
        return [
            str_repeat('a', 2000).'!',
            str_repeat('9', 2000).'x',
            str_repeat('1 ', 1000).'x',
            str_repeat('a.', 1000).'@',
            str_repeat('ab@', 600).'.',
            str_repeat('www.', 500).' ',
            str_repeat('send money ', 180),
            str_repeat('നമസ്കാരം ', 200),
        ];
    }
}
