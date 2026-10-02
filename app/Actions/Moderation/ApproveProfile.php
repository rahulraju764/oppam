<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\DecidesModerationItems;
use App\Domain\Moderation\ModerationFlags;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Events\Admin\ModerationQueueChanged;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Notifications\Profile\ProfileApproved;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Approve a submitted profile (A04): PENDING_REVIEW → ACTIVE (searchable, R-M02-2),
 * published_at set on first publish (which starts the R-M02-1 identity locks), the member is
 * told, the decision audited. An underage profile can never be approved.
 */
final class ApproveProfile
{
    use DecidesModerationItems;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ModerationFlags $flags,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ModerationItemUnavailable
     */
    public function handle(AdminUser $admin, ModerationItem $item): void
    {
        $this->authorizeDecision($admin, $item);

        if ($item->type !== ModerationItemType::ProfileNew) {
            throw new InvalidArgumentException('Not a profile review item.');
        }

        $this->closeIfNoLongerWaiting($admin, $item);

        $profile = DB::transaction(function () use ($admin, $item): Profile {
            $this->holdClaim($admin, $item);
            $profile = $this->lockWaitingProfile($item);

            if ($this->flags->isUnderage($profile)) {
                throw new InvalidArgumentException(__('An underage profile can’t be approved.'));
            }

            $before = ['status' => $profile->status->value];

            $profile->forceFill([
                'status' => ProfileStatus::Active,
                'published_at' => $profile->published_at ?? now(),
            ])->save();

            $this->close($item, $admin, ModerationStatus::Approved);

            $this->audit->record('moderation.profile_approved', $profile, $before, ['status' => ProfileStatus::Active->value], actor: $admin, subjectLabel: $profile->code);

            return $profile;
        });

        ModerationQueueChanged::dispatch(ModerationItemType::ProfileNew);
        $profile->load('user')->user?->notify(new ProfileApproved($profile->code));
    }
}
