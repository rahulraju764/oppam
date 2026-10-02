<?php

declare(strict_types=1);

namespace App\Listeners\Moderation;

use App\Domain\Moderation\ModerationFlags;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Enums\RejectReason;
use App\Enums\WizardStep;
use App\Events\Admin\ModerationQueueChanged;
use App\Events\Profile\ProfileSubmitted;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Notifications\Profile\ProfileNeedsChanges;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * A04 "underage → auto-reject": a submitted profile under the legal marriage age is rejected
 * at once by the system (the wizard already blocks this; the guard matters for imports, P7).
 */
final class AutoRejectUnderage
{
    public function __construct(
        private readonly ModerationFlags $flags,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ProfileSubmitted $event): void
    {
        $profile = Profile::query()->where('code', $event->profileCode)->with('user')->first();
        $item = ModerationItem::query()->whereKey($event->moderationItemId)->first();

        if ($profile === null || $item === null || ! $item->status->isPending() || ! $this->flags->isUnderage($profile)) {
            return;
        }

        DB::transaction(function () use ($profile, $item): void {
            $profile->forceFill(['status' => ProfileStatus::Rejected])->save();
            $item->forceFill([
                'status' => ModerationStatus::Rejected,
                'decided_at' => now(),
                'reason_category' => RejectReason::Underage->value,
                'fields' => ['step' => WizardStep::Basic->value],
            ])->save();

            $this->audit->record('moderation.profile_auto_rejected', $profile, after: ['reason' => RejectReason::Underage->value], subjectLabel: $profile->code);
        });

        ModerationQueueChanged::dispatch(ModerationItemType::ProfileNew);
        $profile->user?->notify(new ProfileNeedsChanges(RejectReason::Underage, null, WizardStep::Basic));
    }
}
