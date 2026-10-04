<?php

declare(strict_types=1);

namespace App\Data\Realtime;

use App\Enums\Flag;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Feature;

/**
 * What the member layout tells the browser about real-time (PRD §9, rendered as <meta> tags):
 * the user channel id (the member's own — Laravel's user channel is keyed by the user ULID, so
 * this is the one place the page carries it), whether to join the online roster (same rule as
 * OnlinePresenceChannel: ACTIVE profile, online status shown, not incognito, not a support
 * session), whether this tab is a support session (it obeys IMPERSONATION-scoped forced
 * logouts) and whether the polling fallback is switched on.
 */
final readonly class RealtimeContextData
{
    public function __construct(
        public string $userId,
        public bool $presence,
        public bool $impersonating,
        public bool $pollingFallback,
    ) {}

    /** Null for guests and non-members: the page opens no socket. */
    public static function current(): ?self
    {
        $user = auth('web')->user();
        if (! $user instanceof User || $user->role !== UserRole::Member || ! $user->isActive()) {
            return null;
        }

        $impersonating = app(Impersonation::class)->isActive();
        $profile = $user->profile;
        $privacy = $profile === null ? null : PrivacySetting::query()->whereKey($profile->id)->first();
        $presence = $profile !== null && $profile->status === ProfileStatus::Active && ! $impersonating
            && ($privacy === null || ($privacy->show_online_status && ! $privacy->incognito));

        return new self(
            userId: (string) $user->id,
            presence: $presence,
            impersonating: $impersonating,
            pollingFallback: Feature::active(Flag::RealtimePollingFallback),
        );
    }
}
