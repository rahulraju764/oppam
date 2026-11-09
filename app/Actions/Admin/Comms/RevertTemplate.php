<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bring back an earlier version of an A14 template (P8.3a, `templates.edit`): its text is saved as a
 * new version (history is append-only), which becomes live.
 */
final class RevertTemplate
{
    public function __construct(
        private readonly SaveTemplate $save,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CommsRefused
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, CommunicationTemplate $template, int $version): CommunicationTemplate
    {
        Gate::forUser($admin)->authorize('templates.edit');

        $old = CommunicationTemplateVersion::query()->where('template_id', $template->id)->where('version', $version)->first()
            ?? throw CommsRefused::noVersion();

        // One transaction: the new version and both audit rows land together, or none of them.
        return DB::transaction(function () use ($admin, $template, $old, $version): CommunicationTemplate {
            $saved = $this->save->handle($admin, $template->event, $template->channel, $old->subject, $old->body, $old->dlt_template_id,
                $template->name, $template->event === CommunicationTemplate::CAMPAIGN_EVENT ? substr($template->key, strlen('campaign.')) : null);

            $this->audit->record('comms.template_reverted', $saved, after: ['from_version' => $version, 'version' => $saved->current_version],
                actor: $admin, subjectLabel: $saved->key);

            return $saved;
        });
    }
}
