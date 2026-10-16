<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Last seen …" for the thread header (PRD M07, §9.6): from `users.last_seen_at` (RecordMemberActivity),
 * only when the member allows it (`privacy_settings.show_last_seen`, default on). "Online now" is not
 * decided here: it comes live from the presence roster, which members who hide their online status
 * never join.
 */
final class LastSeen
{
    public function label(Profile $profile, ?CarbonImmutable $now = null): ?string
    {
        $shown = (bool) (PrivacySetting::query()->whereKey($profile->id)->value('show_last_seen') ?? true);
        $seen = $profile->user_id !== null ? User::query()->whereKey($profile->user_id)->value('last_seen_at') : null;

        if (! $shown || $seen === null) {
            return null;
        }

        $timezone = (string) config('oppam.display_timezone');
        $at = CarbonImmutable::parse((string) $seen, 'UTC')->setTimezone($timezone);
        $now ??= CarbonImmutable::now($timezone);

        return match (true) {
            $at->isSameDay($now) => __('Last seen today at :time', ['time' => $at->format('H:i')]),
            $at->isSameDay($now->subDay()) => __('Last seen yesterday at :time', ['time' => $at->format('H:i')]),
            $at->greaterThan($now->subDays(6)->startOfDay()) => __('Last seen :day', ['day' => $at->translatedFormat('l')]),
            default => __('Last seen :date', ['date' => $at->translatedFormat('j M Y')]),
        };
    }
}
