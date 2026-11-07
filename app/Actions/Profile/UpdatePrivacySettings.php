<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Data\Profile\PrivacySettingsData;
use App\Domain\Profile\Incognito;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The /settings Privacy tab (PRD M14): the member's own privacy_settings row. Every rule that reads
 * these columns lives where it is enforced (PhotoAccess, ContactAccessPolicy, HoroscopeAccess,
 * LastSeen, ReadReceipts, ProfileSearch, SendInterest, Incognito); this only stores the choice.
 *  - incognito can be switched ON only while the plan has Entitlement::Incognito (Diamond);
 *    switching it off, or keeping a stored "on" through a lapse, always works;
 *  - rule 10: at most `members.settings_changes_per_hour` saves — but a save that makes nothing
 *    less private is never refused (a member must always be able to hide);
 *  - support staff in a support session can't change it (the member's own choice).
 *
 * Returns true when the saved settings now keep the member off the online roster.
 */
final class UpdatePrivacySettings
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly Incognito $incognito,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws SettingsRefused
     * @throws NotFoundHttpException
     */
    public function handle(User $member, PrivacySettingsData $wanted): bool
    {
        $this->impersonation->assertAllowed();

        $profile = $member->isActive() ? $member->profile : null;
        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        // A member without a row yet (onboarding normally creates it): insert the defaults first so
        // the lock below always has a row to hold — two saves at once can't both insert.
        DB::table('privacy_settings')->insertOrIgnore(['profile_id' => $profile->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::transaction(function () use ($profile, $wanted): bool {
            $row = PrivacySetting::query()->whereKey($profile->id)->lockForUpdate()->first();
            $before = PrivacySettingsData::from($row);

            if ($wanted->equals($before)) {
                return $this->incognito->hidesPresence($profile, $row);
            }

            if ($wanted->incognito && ! $before->incognito && ! $this->incognito->isAvailable($profile)) {
                throw SettingsRefused::incognitoNeedsPlan();
            }

            if (! $wanted->isNoLooserThan($before)) {
                $key = 'privacy-settings:'.$profile->id;
                if (RateLimiter::tooManyAttempts($key, Settings::int(SettingKey::MembersSettingsChangesPerHour))) {
                    throw SettingsRefused::tooManyChanges();
                }
                RateLimiter::hit($key, 3600);
            }

            $row ??= (new PrivacySetting)->forceFill(['profile_id' => $profile->id]);
            $row->forceFill($wanted->toColumns())->save();

            return $this->incognito->hidesPresence($profile, $row);
        });
    }
}
