<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Actions\Activity\RecordMemberActivity;
use App\Broadcasting\Concerns\ChecksMember;
use App\Enums\ProfileStatus;
use App\Models\PrivacySetting;
use App\Services\Admin\Impersonation;

/**
 * `presence.online` (PRD §9.3 / §9.6): the site-wide "who is online" roster behind the green dots.
 * Joins only for an active member whose profile is ACTIVE (others aren't visible anyway), who
 * shows their online status and isn't incognito — and never while an admin is impersonating
 * (a support session must not make the member look online). Returns the profile code only.
 * Joining also refreshes "last seen". Owner decision 2026-10-05: the roster is shared, so a member
 * could read another online member's code in the raw socket data even across a block — it is
 * never rendered (a blocked profile is a 404 everywhere a dot could appear).
 */
final class OnlinePresenceChannel
{
    use ChecksMember;

    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly RecordMemberActivity $activity,
    ) {}

    /** @return array{code: string}|false */
    public function join(mixed $user): array|false
    {
        $member = $this->activeMember($user);
        $profile = $member === null ? null : $this->profileOf($member);

        if ($member === null || $profile === null || $profile->status !== ProfileStatus::Active || $this->impersonation->isActive()) {
            return false;
        }

        $privacy = PrivacySetting::query()->whereKey($profile->id)->first();
        if ($privacy !== null && (! $privacy->show_online_status || $privacy->incognito)) {
            return false;
        }

        $this->activity->handle($member);

        return ['code' => $profile->code];
    }
}
