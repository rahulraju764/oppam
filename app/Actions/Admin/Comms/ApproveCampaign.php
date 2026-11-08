<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Models\AdminUser;
use App\Models\Campaign;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Second admin approves a large campaign (> 10,000 recipients), PRD §11 A14.
 */
final class ApproveCampaign
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Campaign $campaign): Campaign
    {
        Gate::forUser($admin)->authorize('campaigns.approve');

        if ($campaign->created_by === $admin->id && ! $admin->isSuperAdmin()) {
            throw ValidationException::withMessages(['campaign' => __('Large campaigns (> 10,000) require approval by a different admin.')]);
        }

        if ($campaign->status !== Campaign::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['campaign' => __('This campaign does not require approval.')]);
        }

        $nextStatus = $campaign->scheduled_at !== null ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_APPROVED;

        $campaign->forceFill([
            'status' => $nextStatus,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ])->save();

        $this->audit->record(
            'comms.campaign_approved',
            $campaign,
            after: ['status' => $nextStatus, 'approved_by' => $admin->id],
            actor: $admin,
            subjectLabel: $campaign->name,
        );

        return $campaign;
    }
}
