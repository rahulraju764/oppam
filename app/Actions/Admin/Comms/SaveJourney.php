<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Models\AdminUser;
use App\Models\AutomatedJourney;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Updates an automated journey configuration (A14).
 */
final class SaveJourney
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{is_active?: bool, trigger_days?: int, channel?: string, template_id?: string|null}  $data
     *
     * @throws AuthorizationException
     */
    public function handle(AdminUser $admin, AutomatedJourney $journey, array $data): AutomatedJourney
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        $journey->forceFill([
            'is_active' => $data['is_active'] ?? $journey->is_active,
            'trigger_days' => $data['trigger_days'] ?? $journey->trigger_days,
            'channel' => $data['channel'] ?? $journey->channel,
            'template_id' => $data['template_id'] ?? $journey->template_id,
        ])->save();

        $this->audit->record('comms.journey_updated', $journey, after: ['key' => $journey->key, 'is_active' => $journey->is_active], actor: $admin, subjectLabel: $journey->key);

        return $journey;
    }
}
