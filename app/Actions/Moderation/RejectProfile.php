<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\DecidesModerationItems;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Enums\RejectReason;
use App\Enums\WizardStep;
use App\Events\Admin\ModerationQueueChanged;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Notifications\Profile\ProfileNeedsChanges;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reject a submitted profile, or ask for changes (A04, R-M02-5): PENDING_REVIEW → REJECTED, so the
 * member can edit and resubmit; the category's member message and the moderator's note show in
 * the member's banner (ModerationItem::latestDecisionNote) and email. "Request changes" also
 * names the wizard step to fix (deep link). "Other" needs a note. Audited.
 */
final class RejectProfile
{
    use DecidesModerationItems;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ModerationItemUnavailable
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, ModerationItem $item, RejectReason $reason, ?string $note, ?WizardStep $changesAt = null): void
    {
        $this->authorizeDecision($admin, $item);

        if ($item->type !== ModerationItemType::ProfileNew) {
            throw new InvalidArgumentException('Not a profile review item.');
        }

        $note = $this->validatedNote($note, $reason);
        $step = $changesAt ?? WizardStep::Basic;

        $this->closeIfNoLongerWaiting($admin, $item);

        $profile = DB::transaction(function () use ($admin, $item, $reason, $note, $changesAt, $step): Profile {
            $this->holdClaim($admin, $item);
            $profile = $this->lockWaitingProfile($item);

            $profile->forceFill(['status' => ProfileStatus::Rejected])->save();

            $this->close($item, $admin, $changesAt !== null ? ModerationStatus::ChangesRequested : ModerationStatus::Rejected,
                $reason->value, $note, ['step' => $step->value]);

            $this->audit->record($changesAt !== null ? 'moderation.profile_changes_requested' : 'moderation.profile_rejected', $profile,
                ['status' => ProfileStatus::PendingReview->value], ['status' => ProfileStatus::Rejected->value, 'reason' => $reason->value],
                reason: $note, actor: $admin, subjectLabel: $profile->code);

            return $profile;
        });

        ModerationQueueChanged::dispatch(ModerationItemType::ProfileNew);
        $profile->load('user')->user?->notify(new ProfileNeedsChanges($reason, $note, $step));
    }
}
