<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Domain\Comms\CampaignStarter;
use App\Enums\CampaignStatus;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A14 second approval (P8.3b1, `campaigns.approve`): a campaign above the A15 approval threshold
 * goes out only once a DIFFERENT admin approves it — never the one who created or submitted it,
 * super admins included (decisions 2026-11-08 P8.3b row) — nor anyone who edited the draft. Approving starts it (or schedules it).
 * Declining = cancelling (CancelCampaign). Audited.
 */
final class ApproveCampaign
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CampaignStarter $starter,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CommsRefused
     */
    public function handle(AdminUser $admin, Campaign $campaign): Campaign
    {
        Gate::forUser($admin)->authorize('campaigns.approve');

        return DB::transaction(function () use ($admin, $campaign): Campaign {
            $row = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== CampaignStatus::PendingApproval) {
                throw CommsRefused::campaignNotAwaitingApproval();
            }
            if ($row->created_by === $admin->id || $row->submitted_by === $admin->id || $this->editedBy($row, $admin)) {
                throw CommsRefused::campaignOwnApproval();
            }

            $row->forceFill(['approved_by' => $admin->id, 'approved_at' => now()]);
            $this->starter->begin($row);

            $this->audit->record('comms.campaign_approved', $row,
                after: ['status' => $row->status->value, 'recipients' => $row->recipient_count],
                actor: $admin, subjectLabel: $row->code);

            return $row;
        });
    }

    /** Anyone who ever changed the draft wrote (part of) it — from the append-only audit trail. */
    private function editedBy(Campaign $campaign, AdminUser $admin): bool
    {
        return AuditLog::query()
            ->where('subject_type', class_basename($campaign))->where('subject_id', $campaign->id)   // as AuditLogger stores it
            ->where('actor_id', $admin->id)
            ->whereIn('action', ['comms.campaign_created', 'comms.campaign_updated', 'comms.campaign_submitted'])
            ->exists();
    }
}
