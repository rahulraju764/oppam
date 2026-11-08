<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Domain\Profile\MemberHiding;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Hide / delete: show my profile again (PRD M14). The profile goes back to the status it
 * had before it was hidden. This makes the member visible, so it shares the settings limit
 * (`members.settings_changes_per_hour`, rule 10); hiding never does.
 */
final class UnhideProfile
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Impersonation $impersonation,
        private readonly MemberHiding $hiding,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws SettingsRefused
     * @throws NotFoundHttpException
     */
    public function handle(User $member): Profile
    {
        $this->impersonation->assertAllowed();

        if (! $member->isActive()) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($member): Profile {
            $profile = Profile::query()->where('user_id', $member->id)->lockForUpdate()->first()
                ?? throw new NotFoundHttpException;

            $key = 'profile-visibility:'.$profile->id;
            if (RateLimiter::tooManyAttempts($key, Settings::int(SettingKey::MembersSettingsChangesPerHour))) {
                throw SettingsRefused::tooManyChanges();
            }

            if (! $this->hiding->unhide($profile)) {
                throw SettingsRefused::notHidden();
            }

            RateLimiter::hit($key, 3600);

            $this->audit->record('profile.unhidden_by_member', $profile, ['status' => 'HIDDEN'], ['status' => $profile->status->value],
                actor: $member, subjectLabel: $profile->code);

            return $profile;
        });
    }
}
