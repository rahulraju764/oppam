<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Domain\Profile\CompletenessCalculator;
use App\Domain\Profile\WizardProgress;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Enums\WizardStep;
use App\Events\Profile\ProfileSubmitted;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Submit the finished wizard for review (R-M02-2): DRAFT or REJECTED → PENDING_REVIEW, plus one
 * PROFILE_NEW item in the A04 queue (paid members in the priority lane). The profile row is
 * locked, so a double click can't create two queue items. ProfileSubmitted fires after commit
 * and pushes the new queue count to admins.
 */
final class SubmitProfile
{
    public function __construct(private readonly CompletenessCalculator $completeness) {}

    /**
     * @throws AuthorizationException
     * @throws ProfileNotSubmittable
     */
    public function handle(User $actor, Profile $profile): ModerationItem
    {
        Gate::forUser($actor)->authorize('editWizard', $profile);

        return DB::transaction(function () use ($profile): ModerationItem {
            $locked = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [ProfileStatus::Draft, ProfileStatus::Rejected], true)) {
                throw ProfileNotSubmittable::alreadySubmitted();
            }

            $progress = new WizardProgress($locked);

            if (! $progress->isReadyToSubmit()) {
                $step = $progress->firstIncomplete();

                throw $step === WizardStep::Photos && $progress->hasAbout() && ! $progress->hasPhoto()
                    ? ProfileNotSubmittable::needsPhoto()
                    : ProfileNotSubmittable::incomplete($step);
            }

            $locked->forceFill([
                'status' => ProfileStatus::PendingReview,
                'completeness' => $this->completeness->percent($locked),
            ])->save();

            $item = new ModerationItem;
            $item->forceFill([
                'type' => ModerationItemType::ProfileNew,
                'profile_id' => $locked->id,
                'status' => ModerationStatus::Open,
                'is_priority' => $locked->is_premium,
                'submitted_at' => now(),
            ])->save();

            ProfileSubmitted::dispatch($locked->code, $item->id);

            return $item;
        });
    }
}
