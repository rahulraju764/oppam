<?php

declare(strict_types=1);

namespace App\Actions\Broker\Import;

use App\Actions\Broker\ManagedProfiles\SubmitManagedProfile;
use App\Data\Broker\ImportSubmitResult;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\BatchModerationTally;
use App\Domain\Broker\Import\ImportBatchAccess;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Profile\WizardProgress;
use App\Enums\BureauPermission;
use App\Enums\ProfileStatus;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Models\BrokerImportBatch;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Submit all complete profiles" (§11A B.9 step 6): every DRAFT profile imported by this batch that
 * the caller may see (ManagedProfileScope — an assigned-only login only its own) and that is ready
 * for review goes to A04 through SubmitManagedProfile — the per-profile path, so the same
 * completeness rules, audit and A04 flag ("batch B-0042 · entered by …") apply; never auto-approved
 * (R-M13-14 / R-M13-30). The rest are counted as still needing details. Needs `profiles.submit`.
 */
final class SubmitImportedProfiles
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ImportBatchAccess $access,
        private readonly ManagedProfileScope $scope,
        private readonly SubmitManagedProfile $submit,
        private readonly BatchModerationTally $tally,
    ) {}

    /**
     * @throws HttpException 403 without `profiles.submit`
     * @throws ModelNotFoundException<BrokerImportBatch>
     */
    public function handle(User $actor, string $batchCode): ImportSubmitResult
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ProfilesSubmit);
        $batch = $this->access->find($staff, $batchCode);

        $submitted = 0;
        $incomplete = 0;

        try {
            $this->scope->query($staff)
                ->where('profiles.import_batch_id', $batch->id)
                ->where('profiles.status', ProfileStatus::Draft)
                ->with(['educationCareer', 'familyDetail', 'partnerPreference', 'contactDetail'])   // WizardProgress reads these
                ->orderBy('profiles.id')
                ->each(function (Profile $profile) use ($actor, &$submitted, &$incomplete): void {
                    if (! (new WizardProgress($profile))->isReadyToSubmit()) {
                        $incomplete++;

                        return;
                    }

                    try {
                        $this->submit->handle($actor, $profile->code);
                        $submitted++;
                    } catch (ProfileNotSubmittable|ModelNotFoundException|AuthorizationException) {
                        $incomplete++;   // changed meanwhile (another tab, claimed, re-assigned): the rest still go
                    }
                });
        } finally {
            if ($submitted > 0) {
                $this->tally->refresh($batch);   // also when a later failure ends the loop
            }
        }

        return new ImportSubmitResult($submitted, $incomplete);
    }
}
