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
 * Cancels a campaign in flight or scheduled (PRD §11 A14).
 */
final class CancelCampaign
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Campaign $campaign): Campaign
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        if ($campaign->status === Campaign::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['campaign' => __('Completed campaigns cannot be cancelled.')]);
        }

        $campaign->forceFill([
            'status' => Campaign::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        $this->audit->record('comms.campaign_cancelled', $campaign, after: ['status' => Campaign::STATUS_CANCELLED], actor: $admin, subjectLabel: $campaign->name);

        return $campaign;
    }
}
