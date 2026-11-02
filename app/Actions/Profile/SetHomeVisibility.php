<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Enums\SettingKey;
use App\Exceptions\Admin\ContentRefused;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The member's own "Show me on the home page" choice (owner 2026-11-01: `ALL_MEMBERS` never meant
 * public, so appearing to logged-out visitors takes this explicit opt-in). It is the member's own
 * choice, so support staff in a support session can't change it either way. Audited: it is consent to public display.
 */
final class SetHomeVisibility
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException
     */
    public function handle(User $member, bool $show): void
    {
        $this->impersonation->assertAllowed();

        $profile = $member->isActive() ? $member->profile : null;
        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        DB::transaction(function () use ($member, $profile, $show): void {
            $settings = PrivacySetting::query()->whereKey($profile->id)->lockForUpdate()->first()
                ?? (new PrivacySetting)->forceFill(['profile_id' => $profile->id]);

            if ($settings->show_on_home === $show || (! $show && ! $settings->exists)) {
                return;   // no change, no audit row
            }

            // Only joining is limited. Leaving public display always works at once, and each leave
            // pairs with a limited join, so the audit log still can't be flooded.
            if ($show) {
                $key = 'home-visibility:'.$profile->id;
                if (RateLimiter::tooManyAttempts($key, Settings::int(SettingKey::ContentHomeToggleMaxPerHour))) {
                    throw ContentRefused::tooManyChanges();
                }
                RateLimiter::hit($key, 3600);
            }

            $settings->forceFill(['show_on_home' => $show])->save();

            $this->audit->record('profile.home_visibility_changed', $profile, [], ['show_on_home' => $show], actor: $member, subjectLabel: $profile->code);
        });
    }
}
