<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Actions\Profile\SubmitProfile;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauPermission;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Submit for review" on a managed client (§11A B.6, R-M13-14): `profiles.submit_for_review`,
 * inside ManagedProfileScope, then exactly the member path (SubmitProfile: complete wizard,
 * DRAFT / REJECTED → PENDING_REVIEW, one A04 PROFILE_NEW item). A04 shows the "Added by broker"
 * flag (ModerationFlags); there is no auto-approval. Audited with the staff user.
 */
final class SubmitManagedProfile
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly SubmitProfile $submit,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<Profile>
     * @throws AuthorizationException
     * @throws ProfileNotSubmittable
     */
    public function handle(User $actor, string $code): ModerationItem
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ProfilesSubmit);
        $profile = $this->scope->find($staff, $code);

        $item = $this->submit->handle($actor, $profile);

        $this->audit->record('broker.profile_submitted', $profile, after: ['status' => 'PENDING_REVIEW'], actor: $actor, subjectLabel: $profile->code);

        return $item;
    }
}
