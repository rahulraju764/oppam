<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Models\ContactDetail;
use App\Models\ContactView;
use App\Models\EducationCareer;
use App\Models\FamilyDetail;
use App\Models\HoroscopeDetail;
use App\Models\LifestyleDetail;
use App\Models\LoginEvent;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\NotificationPreference;
use App\Models\OtpChallenge;
use App\Models\PartnerPreference;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Stage two of the two-stage deletion (A03, owner decision 2026-10-02 "anonymise, keep a
 * tombstone"): once the restore window has passed, wipe everything that identifies the member —
 * phone, email, password, names (to "Deleted Member"), date of birth (to 1 January of the same
 * year), about text, contact / family / partner / career / horoscope / lifestyle / privacy rows,
 * photos and the horoscope file, sign-in history, OTP challenges, sessions, profile and contact
 * views, and held moderation text. The account and profile rows stay as a tombstone (code, gender, status DELETED) so
 * subscriptions, orders (P5) and audit rows keep pointing at something. Idempotent: an
 * anonymised or not-yet-due member is skipped. Run by PurgeDeletedMembers (daily).
 */
final class PurgeDeletedMember
{
    public function __construct(private readonly AuditLogger $audit) {}

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
            HoroscopeDetail::class, LifestyleDetail::class, PrivacySetting::class] as $model) {
            $model::query()->where('profile_id', $profile->id)->delete();
        }

        foreach ([ProfileView::class, ContactView::class] as $model) {
            $model::query()->where('viewer_profile_id', $profile->id)->orWhere('viewed_profile_id', $profile->id)->delete();
        }

        ModerationItem::query()->where('profile_id', $profile->id)->update(['fields' => null, 'reason_note' => null]);

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
