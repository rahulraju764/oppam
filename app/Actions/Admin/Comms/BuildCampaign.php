<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Models\AdminUser;
use App\Models\Campaign;
use App\Queries\Admin\Comms\SegmentQuery;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates a targeted campaign and evaluates its recipient count (PRD §11 A14).
 * Campaigns with > 10,000 recipients require second admin approval (PENDING_APPROVAL).
 */
final class BuildCampaign
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SegmentQuery $segmentQuery,
    ) {}

    /**
     * @param  array{name: string, channel: string, segment_filters: array<string, mixed>, message: string, subject?: string|null, template_id?: string|null, scheduled_at?: string|null, throttle_per_minute?: int}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, array $data): Campaign
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        $channel = $data['channel'];
        if (! in_array($channel, ['email', 'sms', 'in_app'], true)) {
            throw ValidationException::withMessages(['channel' => __('Invalid communication channel.')]);
        }

        $filters = $data['segment_filters'];
        $recipientCount = $this->segmentQuery->build($filters, $channel)->count();

        $status = Campaign::STATUS_DRAFT;
        if ($recipientCount > 10_000) {
            $status = Campaign::STATUS_PENDING_APPROVAL;
        } elseif (! empty($data['scheduled_at'])) {
            $status = Campaign::STATUS_SCHEDULED;
        } else {
            $status = Campaign::STATUS_APPROVED;
        }

        $campaign = new Campaign;
        $campaign->forceFill([
            'name' => trim($data['name']),
            'channel' => $channel,
            'segment_filters' => $filters,
            'template_id' => $data['template_id'] ?? null,
            'subject' => ! empty($data['subject']) ? trim($data['subject']) : null,
            'message' => trim($data['message']),
            'status' => $status,
            'scheduled_at' => ! empty($data['scheduled_at']) ? $data['scheduled_at'] : null,
            'throttle_per_minute' => $data['throttle_per_minute'] ?? 60,
            'recipient_count' => $recipientCount,
            'created_by' => $admin->id,
        ]);
        $campaign->save();

        $this->audit->record(
            'comms.campaign_created',
            $campaign,
            after: ['name' => $campaign->name, 'recipient_count' => $recipientCount, 'status' => $status],
            actor: $admin,
            subjectLabel: $campaign->name,
        );

        return $campaign;
    }
}
