<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Data\Broker\ReferralCodeHintData;
use App\Enums\BrokerStatus;
use App\Models\Broker;
use Illuminate\Support\Collection;

/**
 * Referral codes (B.5): one normalisation for the `?ref=` cookie, registration, wizard step 1, the
 * public validate endpoint and the A07 queue — upper-cased, spaces and hyphens removed (R-M13-2,
 * B.19 "brk1042", "BRK 1042"); anything else that can't be a code is ignored, never an error. Only an ACTIVE bureau's code is valid for attribution; an unknown code or
 * one of a bureau that isn't active is kept raw for A07 and never blocks anything (R-M13-1).
 */
final class BrokerCodeLookup
{
    public const MAX_LENGTH = 20;

    /** The cleaned code, or null when nothing usable was given (empty, too long, odd characters). */
    public static function normalise(?string $raw): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s\-]+/u', '', (string) $raw));

        return preg_match('/^[A-Z0-9]{1,'.self::MAX_LENGTH.'}$/', $code) === 1 ? $code : null;
    }

    /** The ACTIVE bureau with this code (R-M13-8: an inactive bureau earns nothing new). */
    public function activeBroker(?string $raw): ?Broker
    {
        $code = self::normalise($raw);

        return $code === null ? null : Broker::query()->where('code', $code)->where('status', BrokerStatus::Active)->with('district:id,label')->first();
    }

    /** The public answer for a code (validate endpoint, inline hint on registration / wizard). */
    public function hint(?string $raw): ReferralCodeHintData
    {
        $broker = $this->activeBroker($raw);

        return $broker === null
            ? new ReferralCodeHintData(false)
            : new ReferralCodeHintData(true, $broker->displayName(), $broker->district?->label);
    }

    /**
     * Active bureau codes within Levenshtein distance 2 of a mistyped one, closest first (A07
     * "fuzzy suggestions"; never auto-linked). Bureaus are counted in thousands at most, so the
     * distance is taken in PHP over the active codes; pass one activePool() for many rows.
     *
     * @param  Collection<int, Broker>|null  $pool
     * @return Collection<int, Broker>
     */
    public function suggestions(string $raw, int $limit = 5, ?Collection $pool = null): Collection
    {
        $code = self::normalise($raw);

        if ($code === null) {
            return collect();
        }

        return ($pool ?? $this->activePool())
            ->map(fn (Broker $broker): array => [$broker, levenshtein($code, $broker->code)])
            ->filter(fn (array $pair): bool => $pair[1] <= 2)
            ->sortBy(fn (array $pair): string => sprintf('%d-%s', $pair[1], $pair[0]->code))
            ->take($limit)
            ->map(fn (array $pair): Broker => $pair[0])
            ->values();
    }

    /** @return Collection<int, Broker> every ACTIVE bureau, with its district, for suggestions */
    public function activePool(): Collection
    {
        return Broker::query()->where('status', BrokerStatus::Active)->with('district:id,label')
            ->get(['id', 'code', 'name', 'bureau_name', 'district_id']);
    }
}
