<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Domain\Engagement\EngagementRestrictions;
use App\Domain\Media\HoroscopeAccess;
use App\Domain\Media\PhotoAccess;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\AccessRequestStatus;
use App\Enums\AccessRequestType;
use App\Enums\HoroscopeVisibility;
use App\Enums\PhotoVisibility;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\AccessRequest;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\AccessRequestNotification;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ask to see a member's photos or horoscope (PRD M06 "Photo / Horoscope request" — free). Only
 * where it makes sense: the owner set that content to ON_REQUEST and the member can't see it yet.
 * One request per type and pair (idempotent while pending; a declined one may be asked again
 * after the interest cooldown). The owner is notified and decides (RespondToAccessRequest).
 */
final class RequestAccess
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly PhotoAccess $photos,
        private readonly HoroscopeAccess $horoscopes,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
        private readonly EngagementRestrictions $restrictions,
    ) {}

    /**
     * @return bool whether a request is pending now
     *
     * @throws NotFoundHttpException the member may not see this profile (or it is their own)
     * @throws EngagementRefused own profile not ACTIVE, nothing to request, declined recently, too fast
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $owner, AccessRequestType $type): bool
    {
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        if ($own === null || $own->id === $owner->id || ! $this->visibility->canView($owner, $member)) {
            throw new NotFoundHttpException;
        }

        if ($own->status !== ProfileStatus::Active) {
            throw EngagementRefused::profileNotActive();
        }

        $this->restrictions->assertCanSend($own);

        if (! $this->requestable($owner, $member, $type)) {
            throw EngagementRefused::notRequestable();
        }

        $key = 'interests:'.$own->id;   // one budget with interests: both reach out to another member
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::InterestsMaxPerMinute))) {
            throw EngagementRefused::tooFast();
        }
        $this->limiter->hit($key, 60);

        $created = DB::transaction(function () use ($own, $owner, $type): bool {
            $request = AccessRequest::query()->where('type', $type->value)
                ->where('requester_profile_id', $own->id)->where('owner_profile_id', $owner->id)->lockForUpdate()->first();

            if ($request?->status === AccessRequestStatus::Pending) {
                return false;
            }

            if ($request?->status === AccessRequestStatus::Declined) {
                $until = $request->responded_at?->copy()->addDays(Settings::int(SettingKey::InterestResendCooldownDays));
                if ($until !== null && $until->isFuture()) {
                    throw EngagementRefused::cooldown($until->timezone((string) config('oppam.display_timezone'))->format('j M Y'));
                }
            }

            $request ??= new AccessRequest;
            $request->type = $type;
            $request->requester_profile_id = $own->id;
            $request->owner_profile_id = $owner->id;
            $request->status = AccessRequestStatus::Pending;
            $request->responded_at = null;
            $request->save();

            return true;
        });

        if ($created && $owner->user_id !== null) {
            User::query()->find($owner->user_id)?->notify(new AccessRequestNotification($type, $own->id));
        }

        return true;
    }

    /** The owner shares this on request, and the member can't open it yet. */
    public function requestable(Profile $owner, User $member, AccessRequestType $type): bool
    {
        return match ($type) {
            AccessRequestType::Photo => ($owner->privacySetting->photo_visibility ?? PhotoVisibility::AllMembers) === PhotoVisibility::OnRequest
                && ! $this->photos->canSeeClearly($owner, $member),
            AccessRequestType::Horoscope => ($owner->privacySetting->horoscope_visibility ?? HoroscopeVisibility::AllMembers) === HoroscopeVisibility::OnRequest
                && $owner->hasMedia(Profile::HOROSCOPE)
                && ! $this->horoscopes->canView($owner, $member),
        };
    }

    /** Whether the member already has a pending request of that type with the owner. */
    public function isPending(Profile $requester, Profile $owner, AccessRequestType $type): bool
    {
        return AccessRequest::query()->where('type', $type->value)->where('requester_profile_id', $requester->id)
            ->where('owner_profile_id', $owner->id)->where('status', AccessRequestStatus::Pending->value)->exists();
    }
}
