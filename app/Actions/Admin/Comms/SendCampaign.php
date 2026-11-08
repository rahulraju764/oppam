<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Jobs\Admin\Comms\SendCampaignJob;
use App\Models\AdminUser;
use App\Models\Campaign;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Triggers sending of an approved campaign (PRD §11 A14).
 */
final class SendCampaign
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Campaign $campaign): Campaign
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        if ($campaign->status === Campaign::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['campaign' => __('This campaign must be approved before sending.')]);
        }

        if (in_array($campaign->status, [Campaign::STATUS_SENDING, Campaign::STATUS_COMPLETED, Campaign::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['campaign' => __('Campaign cannot be sent in its current state.')]);
        }

        $campaign->forceFill([
            'status' => Campaign::STATUS_SENDING,
            'started_at' => now(),
        ])->save();

        SendCampaignJob::dispatch($campaign->id);

        $this->audit->record(
            'comms.campaign_started',
            $campaign,
            after: ['status' => Campaign::STATUS_SENDING],
            actor: $admin,
            subjectLabel: $campaign->name,
        );

        return $campaign;
    }
}
