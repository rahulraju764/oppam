<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A14 cancel (P8.3b1): stops a draft, a campaign waiting for approval (= declining it), a scheduled
 * one, or one in flight. Needs `campaigns.send`, or `campaigns.approve` to decline one waiting for
 * approval. Members not reached yet are marked SKIPPED (cancelled); a batch already running stops
 * at its next status check (every 50 members, SendCampaignBatch). Final: never restarted. Audited.
 */
final class CancelCampaign
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws CommsRefused
     */
    public function handle(AdminUser $admin, Campaign $campaign): Campaign
    {
        $gate = Gate::forUser($admin);
        $mayDecline = fn (Campaign $c): bool => $c->status === CampaignStatus::PendingApproval && $gate->allows('campaigns.approve');
        if (! $gate->allows('campaigns.send') && ! $mayDecline($campaign)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($admin, $campaign, $gate, $mayDecline): Campaign {
            $row = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            // Re-checked on the locked row: an approver-only admin can't cancel one approved meanwhile.
            if (! $gate->allows('campaigns.send') && ! $mayDecline($row)) {
                throw new AuthorizationException;
            }
            if ($row->status->isFinal()) {
                throw CommsRefused::campaignClosed();
            }

            $before = $row->status;
            $row->forceFill([
                'status' => CampaignStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $admin->id,
            ])->save();

            $notReached = CampaignRecipient::query()->where('campaign_id', $row->id)
                ->where('status', CampaignRecipientStatus::Pending->value)
                ->update(['status' => CampaignRecipientStatus::Skipped->value, 'skip_reason' => CampaignSkipReason::Cancelled->value]);

            $this->audit->record('comms.campaign_cancelled', $row,
                before: ['status' => $before->value], after: ['status' => CampaignStatus::Cancelled->value, 'not_reached' => $notReached],
                actor: $admin, subjectLabel: $row->code);

            return $row;
        });
    }
}
