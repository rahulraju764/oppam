<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The member's own "Show me on the home page" choice (owner 2026-11-01: `ALL_MEMBERS` never meant
 * public, so appearing to logged-out visitors takes this explicit opt-in). Support staff in a
 * support session can't turn it on for them. Audited: it is consent to public display.
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

        $settings = PrivacySetting::query()->find($profile->id) ?? (new PrivacySetting)->forceFill(['profile_id' => $profile->id]);
        $settings->forceFill(['show_on_home' => $show])->save();

        $this->audit->record('profile.home_visibility_changed', $profile, [], ['show_on_home' => $show], actor: $member, subjectLabel: $profile->code);
    }
}
