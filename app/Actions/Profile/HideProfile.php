<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Domain\Profile\MemberHiding;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Hide / delete: hide my profile (PRD M14 — reversible any time). Only a live (ACTIVE)
 * profile can be hidden; it then leaves search, matches, featured members and the online roster.
 * Making a profile more private is never rate-limited. Refused in an admin support session (the
 * member's own choice, decisions 2026-11-07 (c)).
 */
final class HideProfile
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

            if (! $this->hiding->hide($profile)) {
                throw SettingsRefused::notHideable();
            }

            $this->audit->record('profile.hidden_by_member', $profile, ['status' => 'ACTIVE'], ['status' => $profile->status->value],
                actor: $member, subjectLabel: $profile->code);

            return $profile;
        });
    }
}
