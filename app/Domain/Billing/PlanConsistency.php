<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\Entitlement;

/**
 * A06 "display features vs enforced entitlements with consistency warning": the card's wording is
 * free text, so this compares each line that talks about a limited feature with what is enforced.
 * A warning, never a block — the wording may be deliberate.
 */
final class PlanConsistency
{
    /** What a display line says when it is about this entitlement. */
    private const WORDS = [
        Entitlement::LikesPerDay->value => '/\blikes?\b/i',
        Entitlement::FavoritesTotal->value => '/\bfavou?rites?\b/i',
        Entitlement::InterestsPerMonth->value => '/\binterests?\b/i',
        Entitlement::ContactViewsPerMonth->value => '/\bcontacts?\b/i',
        Entitlement::ChatSend->value => '/\b(chat|message)/i',
        Entitlement::SeeWhoLikedMe->value => '/\bwho liked\b/i',
        Entitlement::SeeWhoViewedMe->value => '/\bwho viewed\b/i',
        Entitlement::SearchHighlight->value => '/\bhighlight/i',
        Entitlement::TopPlacement->value => '/\btop (placement|of)\b/i',
        Entitlement::Incognito->value => '/\bincognito\b/i',
    ];

    /**
     * @param  list<string>  $lines  the card's display features
     * @param  array<string, array{enabled: bool, limit: int|null}>  $enforced  by Entitlement value
     * @return list<string> one warning per mismatch
     */
    public function warnings(array $lines, array $enforced): array
    {
        $warnings = [];

        foreach (self::WORDS as $entitlement => $words) {
            $rule = $enforced[$entitlement] ?? null;
            foreach ($lines as $line) {
                if ($rule === null || preg_match($words, $line) !== 1) {
                    continue;
                }

                $label = Entitlement::from($entitlement)->label();
                $shown = preg_match('/\d[\d,]*/', $line, $number) === 1 ? (int) str_replace(',', '', $number[0]) : null;

                if (! $rule['enabled'] || ($rule['limit'] === 0 && ! Entitlement::from($entitlement)->isFlag())) {
                    $warnings[] = __('The card says ":line", but :feature is off.', ['line' => $line, 'feature' => $label]);
                } elseif ($shown !== null && ! Entitlement::from($entitlement)->isFlag() && $shown !== $rule['limit']) {
                    $warnings[] = __('The card says ":line", but :feature is enforced at :limit.', [
                        'line' => $line, 'feature' => $label, 'limit' => $rule['limit'] ?? __('unlimited'),
                    ]);
                }
            }
        }

        return $warnings;
    }
}
