<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Data\Engagement\InterestResult;
use App\Domain\Engagement\Connections;
use App\Domain\Engagement\InterestMessages;
use App\Domain\Engagement\InterestSignals;
use App\Domain\Matching\DailyBatch;
use App\Domain\Matching\PreferenceMatcher;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\Entitlement;
use App\Enums\InterestStatus;
use App\Enums\NotificationEvent;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\DailyMatch;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\InterestNotification;
use App\Notifications\Support\OncePerDay;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Send an interest — the formal proposal (PRD M06, F02).
 *
 * The sender needs an ACTIVE profile and a target they may see (else 404). Refused with a reason:
 * the target's contact filter excludes the sender (R-M06-3), the pair is already connected, or the
 * target declined within the cooldown (`interest.resend_cooldown_days`). An interest already
 * pending is simply returned (idempotent, R-M06-1). If the TARGET has a pending interest to the
 * sender, this send accepts it instead — both want the same thing, and it costs no quota.
 *
 * A new send uses one of the month's interests (§7.3) in the same transaction as the row, never
 * given back (withdrawal and expiry don't refund — owner decision 2026-10-07). One row per
 * direction: a re-send after a withdrawal, an expiry or the cooldown reuses it; interest_events
 * keeps the history. The target is notified (in-app, email, SMS per preferences — at most once per
 * sender and day) and both open pages are told live.
 */
final class SendInterest
{
    private const SENT = 'sent';

    private const ALREADY_PENDING = 'pending';

    private const CROSSED = 'crossed';

    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly PreferenceMatcher $matcher,
        private readonly EntitlementService $entitlements,
        private readonly InterestMessages $messages,
        private readonly Connections $connections,
        private readonly InterestSignals $signals,
        private readonly AcceptInterest $accept,
        private readonly RateLimiter $limiter,
        private readonly OncePerDay $once,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws NotFoundHttpException the member may not see this profile (or it is their own)
     * @throws EngagementRefused own profile not ACTIVE, contact filter, cooldown, already connected, custom note on Free, too fast
     * @throws ValidationException the note (keys `template`, `message`)
     * @throws QuotaExceeded this month's interests are used up
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $target, ?string $templateCode = null, ?string $customMessage = null): InterestResult
    {
        // Contacts another member on the member's behalf and spends their monthly quota.
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        if ($own === null || $own->id === $target->id || ! $this->visibility->canView($target, $member)) {
            throw new NotFoundHttpException;
        }

        if ($own->status !== ProfileStatus::Active) {
            throw EngagementRefused::profileNotActive();
        }

        $state = $this->connections->stateWith($own, $target);

        if ($state === Connections::CONNECTED) {
            throw EngagementRefused::alreadyConnected();
        }

        if ($state === Connections::SENT) {
            return new InterestResult(Connections::SENT, changed: false);
        }

        if ($state === Connections::RECEIVED) {
            $this->accept->handle($member, $target);

            return new InterestResult(Connections::CONNECTED, changed: true, connected: true);
        }

        if (($target->privacySetting->contact_filter_enabled ?? false) && ! $this->matcher->meetsAll($own, $target->partnerPreference)) {
            throw EngagementRefused::contactFilter();
        }

        $message = $this->messages->resolve($own, $templateCode, $customMessage);

        $key = 'interests:'.$own->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::InterestsMaxPerMinute))) {
            throw EngagementRefused::tooFast();
        }
        $this->limiter->hit($key, 60);

        try {
            $outcome = $this->store($own, $target, $message);
        } catch (UniqueConstraintViolationException) {
            $outcome = self::ALREADY_PENDING;   // two tabs sent the very first interest at once: the other one stored (and paid for) it
        }

        if ($outcome === self::CROSSED) {
            // The other member's interest arrived between the first look and the lock: accept it instead.
            $this->accept->handle($member, $target);

            return new InterestResult(Connections::CONNECTED, changed: true, connected: true);
        }

        if ($outcome === self::ALREADY_PENDING) {
            return new InterestResult(Connections::SENT, changed: false);
        }

        // A daily match the member acted on leaves today's slider (M05).
        DailyMatch::query()->where('profile_id', $own->id)->where('matched_profile_id', $target->id)
            ->where('match_date', DailyBatch::today())->update(['is_interacted' => true, 'updated_at' => now()]);

        $this->signals->changed($own, $target);

        // At most one "sent you an interest" per sender and day (R-M08-2 spirit): withdrawing and
        // sending again must not become a way to ring someone's phone repeatedly. The interest
        // itself is in their Received list either way.
        if ($target->user_id !== null && $this->once->claim($target->user_id, NotificationEvent::InterestReceived, $own->code)) {
            User::query()->find($target->user_id)?->notify(new InterestNotification(NotificationEvent::InterestReceived, $own->id));
        }

        return new InterestResult(Connections::SENT, changed: true);
    }

    /**
     * Write the interest. Both directions of the pair are locked in one statement (by id, so two
     * members sending to each other at the same moment take the locks in the same order) and the
     * decision is made again on what is really there.
     *
     * @return string self::SENT, ::ALREADY_PENDING (another tab sent it) or ::CROSSED (theirs is pending: accept it)
     */
    private function store(Profile $own, Profile $target, ?string $message): string
    {
        return DB::transaction(function () use ($own, $target, $message): string {
            $rows = Interest::query()
                ->where(fn ($q) => $q
                    ->where(fn ($x) => $x->where('from_profile_id', $own->id)->where('to_profile_id', $target->id))
                    ->orWhere(fn ($x) => $x->where('from_profile_id', $target->id)->where('to_profile_id', $own->id)))
                ->orderBy('id')->lockForUpdate()->get();
            $interest = $rows->first(fn (Interest $row): bool => $row->from_profile_id === $own->id);
            $theirs = $rows->first(fn (Interest $row): bool => $row->from_profile_id === $target->id);

            if ($interest?->status === InterestStatus::Accepted || $theirs?->status === InterestStatus::Accepted) {
                throw EngagementRefused::alreadyConnected();
            }

            if ($theirs?->status === InterestStatus::Pending && $theirs->expires_at->isFuture()) {
                return self::CROSSED;
            }

            if ($interest?->status === InterestStatus::Pending && $interest->expires_at->isFuture()) {
                return self::ALREADY_PENDING;   // nothing more to do, nothing charged
            }

            if ($interest?->status === InterestStatus::Pending) {
                // Past its expiry but not swept by the hourly job yet: close it in the history, then send anew.
                InterestEvent::record($interest, InterestStatus::Expired, null);
            }

            if ($interest?->status === InterestStatus::Declined) {
                $until = $interest->responded_at?->copy()->addDays(Settings::int(SettingKey::InterestResendCooldownDays));
                if ($until !== null && $until->isFuture()) {
                    throw EngagementRefused::cooldown($until->timezone((string) config('oppam.display_timezone'))->format('j M Y'));
                }
            }

            $interest ??= new Interest;
            $interest->from_profile_id = $own->id;
            $interest->to_profile_id = $target->id;
            $interest->status = InterestStatus::Pending;
            $interest->message = $message;
            $interest->sent_at = now();
            $interest->responded_at = null;
            $interest->expires_at = now()->addDays(Settings::int(SettingKey::InterestExpiryDays));
            $interest->save();

            InterestEvent::record($interest, InterestStatus::Pending, $own->id);
            $this->entitlements->consume($own, Entitlement::InterestsPerMonth);   // QuotaExceeded rolls everything back

            return self::SENT;
        });
    }
}
