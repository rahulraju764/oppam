<?php

declare(strict_types=1);

namespace App\Domain\Engagement;

use App\Exceptions\Engagement\EngagementRefused;
use App\Models\MemberRestriction;
use App\Models\Profile;
use Carbon\CarbonImmutable;

/**
 * "Is this member paused?" (PRD A13 one-click rate-limit, decisions 2026-10-18) — the one place for
 * that rule. While a moderator's pause runs, every outgoing action is refused: ToggleLike (liking),
 * ToggleFavorite (adding), SendInterest(s), RequestAccess and SendMessage (text, photo, icebreaker).
 * Taking things back (unlike, remove a favourite, withdraw, unsend) and answering what others sent
 * (accept / decline) stay open. Memoised per request (scoped binding).
 */
final class EngagementRestrictions
{
    /** @var array<string, CarbonImmutable|null> */
    private array $until = [];

    /** When the member's pause ends (IST-agnostic instant), or null when they may send. */
    public function pausedUntil(Profile $profile): ?CarbonImmutable
    {
        if (! array_key_exists($profile->id, $this->until)) {
            $until = MemberRestriction::query()->where('profile_id', $profile->id)->active()->max('until');
            $this->until[$profile->id] = $until !== null ? CarbonImmutable::parse((string) $until) : null;
        }

        return $this->until[$profile->id];
    }

    /** @throws EngagementRefused while the member's sending is paused */
    public function assertCanSend(Profile $profile): void
    {
        $until = $this->pausedUntil($profile);
        if ($until !== null) {
            throw EngagementRefused::paused($this->untilLabel($until));
        }
    }

    /** The pause's end as the member reads it (IST). */
    public function untilLabel(CarbonImmutable $until): string
    {
        return $until->setTimezone((string) config('oppam.display_timezone'))->format('d M Y, h:i A');
    }

    public function forget(Profile $profile): void
    {
        unset($this->until[$profile->id]);
    }
}
