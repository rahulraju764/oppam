<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Verification\PurgeVerificationDocuments;
use App\Enums\Entitlement;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Jobs\Chat\PruneChatHistory;
use App\Models\AccessRequest;
use App\Models\ContactDetail;
use App\Models\ContactView;
use App\Models\ConversationParticipant;
use App\Models\DailyMatch;
use App\Models\EducationCareer;
use App\Models\EngagementSignal;
use App\Models\FamilyDetail;
use App\Models\Favorite;
use App\Models\HoroscopeDetail;
use App\Models\Ignore;
use App\Models\Interest;
use App\Models\LifestyleDetail;
use App\Models\Like;
use App\Models\LoginEvent;
use App\Models\MatchScore;
use App\Models\Media;
use App\Models\MemberRestriction;
use App\Models\MessageFlag;
use App\Models\ModerationItem;
use App\Models\NotificationPreference;
use App\Models\OtpChallenge;
use App\Models\PartnerPreference;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\Report;
use App\Models\SavedSearch;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Entitlements\EntitlementService;
use App\Support\Facades\Settings;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stage two of the two-stage deletion (A03, owner decision 2026-10-02 "anonymise, keep a
 * tombstone"): once the restore window has passed, wipe everything that identifies the member —
 * phone, email, password, names (to "Deleted Member"), date of birth (to 1 January of the same
 * year), about text, contact / family / partner / career / horoscope / lifestyle / privacy rows,
 * photos and the horoscope file, sign-in history, OTP challenges, sessions, profile and contact
 * views, likes, favourites, interests and photo / horoscope requests (both directions), chat reports about them (reports they filed stay,
 * without their text) and the excerpts of their flagged messages, in-app notifications (theirs, and others' that
 * name them), held moderation text, and ID documents (P6.1: files deleted and verified gone at once,
 * open requests cancelled; the decision and the masked number stay). Chat threads (R-M07-6) stay for the other member — they read "Deleted Member" —
 * until that member is purged too; then the thread and its messages are deleted. The account and profile rows stay as a tombstone (code, gender, status DELETED) so
 * subscriptions, orders (P5) and audit rows keep pointing at something. Idempotent: an
 * anonymised or not-yet-due member is skipped. Run by PurgeDeletedMembers (daily).
 */
final class PurgeDeletedMember
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
        private readonly PurgeVerificationDocuments $verificationFiles,
    ) {}

    /** @return bool whether this member was anonymised now */
    public function handle(User $member): bool
    {
        if (! $this->isDue($member)) {
            return false;
        }

        $profile = Profile::withTrashed()->where('user_id', $member->id)->first();

        // Files first (outside the transaction — a deleted file can't be rolled back); a failed
        // run leaves the member un-anonymised, so the next run tries again.
        if ($profile !== null) {
            Media::query()->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)->get()
                ->each(fn (Media $media) => $media->delete());

            // ID documents (M09): never kept for a purged member, whatever the 90-day clock says.
            VerificationRequest::query()->where('profile_id', $profile->id)->whereNull('documents_purged_at')->get()
                ->each(function (VerificationRequest $request): void {
                    if (! $this->verificationFiles->handle($request)) {
                        throw new RuntimeException('An ID document could not be confirmed deleted; the purge will run again.');
                    }
                });
        }

        return DB::transaction(function () use ($member, $profile): bool {
            $member = User::withTrashed()->whereKey($member->id)->lockForUpdate()->firstOrFail();

            if (! $this->isDue($member)) {
                return false;
            }

            if ($profile !== null) {
                $this->anonymiseProfile($profile);
            }

            NotificationPreference::query()->where('user_id', $member->id)->delete();
            // In-app notifications (P3.2): the member's own, and other members' rows that name
            // this member as the actor ("… viewed your profile" keeps a name and a code).
            DatabaseNotification::query()->where('notifiable_type', $member->getMorphClass())->where('notifiable_id', $member->id)->delete();
            if ($profile !== null) {
                DatabaseNotification::query()->where('data->actor_code', $profile->code)->delete();
            }
            LoginEvent::query()->where('user_id', $member->id)->delete();
            // OTP challenges carry the real phone + IP; sessions the IP + browser.
            OtpChallenge::query()->where('user_id', $member->id)->orWhere('phone', $member->phone)->delete();
            DB::table('sessions')->where('user_id', $member->id)->delete();

            $member->forceFill([
                // Unique, never a real number, frees the real one for a new registration.
                'phone' => 'X'.strtoupper(substr((string) $member->id, -15)),
                'email' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'phone_verified_at' => null,
                'email_verified_at' => null,
                'anonymised_at' => now(),
            ])->save();

            $this->audit->record('members.purged', $member, after: ['anonymised' => true], subjectLabel: $profile?->code);

            return true;
        });
    }

    private function isDue(User $member): bool
    {
        return $member->role === UserRole::Member   // brokers are never purged through A03
            && $member->trashed()
            && $member->anonymised_at === null
            && $member->deleted_at?->lte(now()->subDays(Settings::int(SettingKey::MembersPurgeAfterDays))) === true;
    }

    private function anonymiseProfile(Profile $profile): void
    {
        foreach ([ContactDetail::class, FamilyDetail::class, PartnerPreference::class, EducationCareer::class,
            HoroscopeDetail::class, LifestyleDetail::class, PrivacySetting::class, SavedSearch::class] as $model) {
            $model::query()->where('profile_id', $profile->id)->delete();
        }

        foreach ([ProfileView::class, ContactView::class] as $model) {
            $model::query()->where('viewer_profile_id', $profile->id)->orWhere('viewed_profile_id', $profile->id)->delete();
        }

        // Phase 2 matching data, both directions (P2.4).
        DailyMatch::query()->where('profile_id', $profile->id)->orWhere('matched_profile_id', $profile->id)->delete();
        MatchScore::query()->where('source_profile_id', $profile->id)->orWhere('target_profile_id', $profile->id)->delete();
        Ignore::query()->where('ignorer_profile_id', $profile->id)->orWhere('ignored_profile_id', $profile->id)->delete();

        // Interests (their history goes with them; a conversation they opened keeps running without the
        // link) and photo / horoscope requests, both directions (P3.4).
        Interest::query()->where('from_profile_id', $profile->id)->orWhere('to_profile_id', $profile->id)->delete();
        AccessRequest::query()->where('requester_profile_id', $profile->id)->orWhere('owner_profile_id', $profile->id)->delete();

        // Likes and favourites, both directions, and the member's abuse signals (P3.3).
        Like::query()->where('from_profile_id', $profile->id)->orWhere('to_profile_id', $profile->id)->delete();
        // Members who had bookmarked this profile get that slot of their plan's total back.
        $bookmarkerIds = Favorite::query()->where('favorite_profile_id', $profile->id)->pluck('profile_id')->all();
        Favorite::query()->where('profile_id', $profile->id)->orWhere('favorite_profile_id', $profile->id)->delete();
        foreach (array_chunk($bookmarkerIds, 200) as $ids) {
            Profile::withTrashed()->whereKey($ids)->get(['id'])
                ->each(fn (Profile $owner) => $this->entitlements->release($owner, Entitlement::FavoritesTotal));
        }
        // A13 pauses on the member go before their signals (a pause names its signal).
        MemberRestriction::query()->where('profile_id', $profile->id)->delete();
        EngagementSignal::query()->where('profile_id', $profile->id)->delete();

        // Chat (P4.1, R-M07-6): messages are deleted once BOTH members are gone — a thread whose
        // other member was purged earlier goes now, with its messages (cascade); the rest is kept.
        $threadIds = ConversationParticipant::query()->where('profile_id', $profile->id)->pluck('conversation_id')->all();
        foreach (array_chunk($threadIds, 200) as $ids) {
            $bothGone = ConversationParticipant::query()
                ->join('profiles', 'profiles.id', '=', 'conversation_participants.profile_id')
                ->join('users', 'users.id', '=', 'profiles.user_id')
                ->whereIn('conversation_participants.conversation_id', $ids)
                ->where('conversation_participants.profile_id', '!=', $profile->id)
                ->whereNotNull('users.anonymised_at')
                ->pluck('conversation_participants.conversation_id')->all();
            PruneChatHistory::deleteThreads(array_values(array_map('strval', $bothGone)));   // image files too (P4.2)
        }

        // Chat safety (P4.3a): reports the member FILED stay — they are the evidence on someone else (a
        // harassed member often deletes their account) and point at this tombstone; only the text they
        // typed goes. Reports ABOUT the member go (their REPORT flags with them, cascade). The masked /
        // matched excerpts of the member's own flagged messages are wiped.
        // A10 legal hold (P6.2): details of reports about a member under legal hold are evidence — they stay.
        Report::query()->where('reporter_profile_id', $profile->id)->whereNotNull('details')
            ->whereNotIn('reported_profile_id', Profile::withTrashed()->whereNotNull('legal_hold_at')->select('id'))
            ->update(['details' => null]);
        Report::query()->where('reported_profile_id', $profile->id)->delete();
        MessageFlag::query()->where('sender_profile_id', $profile->id)->whereNotNull('excerpt')->update(['excerpt' => null]);

        ModerationItem::query()->where('profile_id', $profile->id)->update(['fields' => null, 'reason_note' => null]);

        // ID verification (P6.1): a waiting request closes; the name typed for the document goes.
        VerificationRequest::query()->where('profile_id', $profile->id)->whereIn('status', VerificationStatus::open())
            ->update(['status' => VerificationStatus::Cancelled->value, 'decided_at' => now(), 'claimed_by_admin_id' => null, 'claimed_until' => null]);
        VerificationRequest::query()->where('profile_id', $profile->id)->update(['name_on_document' => null, 'flags' => null, 'member_note' => null]);

        $profile->forceFill([
            'first_name' => 'Deleted',
            'last_name' => 'Member',
            'dob' => $profile->dob?->startOfYear(),
            'about' => null,
            'sub_caste' => null,
            'weight_kg' => null,
        ])->save();
    }
}
