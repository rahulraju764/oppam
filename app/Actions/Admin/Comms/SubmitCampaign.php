<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Domain\Comms\CampaignStarter;
use App\Enums\CampaignStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Queries\Admin\Comms\SegmentQuery;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A14 "Send" on a draft campaign (P8.3b1, `campaigns.send`). The audience is FROZEN now: every
 * member the segment reaches on the channel at this moment gets a campaign_recipients row (one
 * INSERT … SELECT), and only they can receive it — each is re-checked again when their turn comes
 * (SendCampaignBatch). Then:
 *  - nobody matches → refused, nothing kept;
 *  - more members than A15 `campaigns.approval_threshold` (10,000) → PENDING_APPROVAL: a different
 *    admin with `campaigns.approve` must approve it (ApproveCampaign);
 *  - otherwise → SCHEDULED (start time ahead) or SENDING now (CampaignStarter).
 * Audited with the audience size.
 */
final class SubmitCampaign
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SegmentQuery $segments,
        private readonly CampaignStarter $starter,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CommsRefused
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Campaign $campaign): Campaign
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        return DB::transaction(function () use ($admin, $campaign): Campaign {
            $row = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== CampaignStatus::Draft) {
                throw CommsRefused::campaignNotDraft();
            }
            if ($row->scheduled_at !== null && ! $row->scheduled_at->isFuture()) {
                throw ValidationException::withMessages(['scheduledAt' => __('The start time has passed. Change it, or clear it to send now.')]);
            }

            $audience = $this->segments->build($row->segment, $row->channel)->toBase()
                ->selectRaw('? as campaign_id, users.id as user_id, ? as status', [$row->id, 'PENDING'])
                ->distinct();
            $count = DB::table('campaign_recipients')->insertUsing(['campaign_id', 'user_id', 'status'], $audience);
            if ($count === 0) {
                throw CommsRefused::campaignEmptyAudience();
            }

            $row->forceFill([
                'recipient_count' => $count,
                'submitted_by' => $admin->id,
                'submitted_at' => now(),
            ]);

            if ($count > Settings::int(SettingKey::CampaignsApprovalThreshold)) {
                $row->status = CampaignStatus::PendingApproval;
                $row->save();
            } else {
                $this->starter->begin($row);
            }

            $this->audit->record('comms.campaign_submitted', $row,
                after: ['status' => $row->status->value, 'recipients' => $count, 'channel' => $row->channel->value],
                actor: $admin, subjectLabel: $row->code);

            return $row;
        });
    }
}
