<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Data\Engagement\LikeResult;
use App\Domain\Engagement\LikeSpamGuard;
use App\Domain\Matching\DailyBatch;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\Entitlement;
use App\Enums\NotificationEvent;
use App\Enums\ProfileStatus;
use App\Events\Engagement\LikeChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\DailyMatch;
use App\Models\Like;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\MutualLikeNotification;
use App\Notifications\Engagement\ProfileLikedNotification;
use App\Notifications\Support\OncePerDay;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Like or un-like a profile (PRD M06, F02). The caller says which state it wants, so a repeat —
 * a double click, two tabs — changes nothing (R-M06-1).
 *
 * Like: the member's own profile must be ACTIVE and they must be allowed to see the target (else
 * 404). A NEW like uses one of today's likes (EntitlementService, §7.3) in the same transaction as
 * the row, after the spam guard (R-M06-2). Then: the target hears about it live (`.like.changed`)
 * and gets a notification at most once per liker per day (R-M08-2) — "It's a match!" for both when
 * the like is mutual.
 *
 * Un-like: removes the member's own row and nothing else — no visibility check (a like on a
 * profile that has since been hidden can still be taken back), no notification ever (R-M06-5),
 * and today's like is not given back (un-liking must not turn the daily limit into a way to
 * re-notify people).
 */
final class ToggleLike
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly EntitlementService $entitlements,
        private readonly LikeSpamGuard $spam,
        private readonly OncePerDay $once,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws NotFoundHttpException the member may not see this profile (or it is their own)
     * @throws EngagementRefused own profile not ACTIVE, or the like-spam soft block
     * @throws QuotaExceeded today's likes are used up
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $target, bool $like): LikeResult
    {
        // Contacts another member on the member's behalf and spends their daily likes.
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        if ($own === null || $own->id === $target->id) {
            throw new NotFoundHttpException;
        }

        return $like ? $this->like($member, $own, $target) : $this->unlike($own, $target);
    }

    private function like(User $member, Profile $own, Profile $target): LikeResult
    {
        if (! $this->visibility->canView($target, $member)) {
            throw new NotFoundHttpException;
        }

        if ($own->status !== ProfileStatus::Active) {
            throw EngagementRefused::profileNotActive();
        }

        if ($this->exists($own, $target)) {
            return new LikeResult(liked: true, mutual: $this->exists($target, $own), changed: false);
        }

        $this->spam->check($own);

        $created = DB::transaction(function () use ($own, $target): bool {
            // insertOrIgnore on the unique pair: two tabs liking at once leave one row and one charge.
            $inserted = Like::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'from_profile_id' => $own->id,
                'to_profile_id' => $target->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                $this->entitlements->consume($own, Entitlement::LikesPerDay);   // QuotaExceeded rolls the row back
            }

            return $inserted === 1;
        });

        $mutual = $this->exists($target, $own);

        if ($created) {
            $this->spam->record($own);
            $this->afterLike($member, $own, $target, $mutual);
        }

        return new LikeResult(liked: true, mutual: $mutual, changed: $created);
    }

    private function unlike(Profile $own, Profile $target): LikeResult
    {
        $removed = Like::query()->where('from_profile_id', $own->id)->where('to_profile_id', $target->id)->delete() > 0;

        if ($removed && $target->user_id !== null) {
            // Only "your lists changed": no name, no notification (R-M06-5).
            LikeChanged::dispatch($target->user_id, $own->id, $target->id, null, false, false);
        }

        return new LikeResult(liked: false, mutual: false, changed: $removed);
    }

    private function afterLike(User $member, Profile $own, Profile $target, bool $mutual): void
    {
        // A daily match the member acted on leaves today's slider (M05).
        DailyMatch::query()->where('profile_id', $own->id)->where('matched_profile_id', $target->id)
            ->where('match_date', DailyBatch::today())->update(['is_interacted' => true, 'updated_at' => now()]);

        $recipient = $target->user_id !== null ? User::query()->find($target->user_id) : null;
        if ($recipient === null) {
            return;   // a broker-managed profile without a login: its bureau hears about it in P7
        }

        $named = $mutual || $this->entitlements->allows($target, Entitlement::SeeWhoLikedMe);
        LikeChanged::dispatch($recipient->id, $own->id, $target->id, $named ? $own->code : null, true, $mutual);

        if ($mutual) {
            // One claim for the PAIR (codes sorted), whichever side completes it — also when both like at once.
            $pair = [$own->code, $target->code];
            sort($pair, SORT_STRING);

            if ($this->once->claim('pair', NotificationEvent::MutualLike, implode('|', $pair))) {
                $recipient->notify(new MutualLikeNotification($own->id));
                $member->notify(new MutualLikeNotification($target->id));
            }

            return;
        }

        if ($this->once->claim($recipient->id, NotificationEvent::ProfileLiked, $own->code)) {
            $recipient->notify(new ProfileLikedNotification($own->id));
        }
    }

    private function exists(Profile $from, Profile $to): bool
    {
        return Like::query()->where('from_profile_id', $from->id)->where('to_profile_id', $to->id)->exists();
    }
}
