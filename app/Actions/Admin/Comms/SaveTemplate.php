<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a communication template (A14).
 */
final class SaveTemplate
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{key: string, name: string, channel: string, subject?: string|null, dlt_template_id?: string|null, body: string, variables?: array<string, string>|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, array $data, ?CommunicationTemplate $template = null): CommunicationTemplate
    {
        Gate::forUser($admin)->authorize('templates.edit');

        return DB::transaction(function () use ($admin, $data, $template): CommunicationTemplate {
            $key = mb_strtolower(trim($data['key']));
            $existing = CommunicationTemplate::query()->where('key', $key)->first();

            if ($template === null && $existing !== null) {
                throw ValidationException::withMessages(['key' => __('A template with this key already exists.')]);
            }

            if ($template !== null && $existing !== null && $existing->id !== $template->id) {
                throw ValidationException::withMessages(['key' => __('A template with this key already exists.')]);
            }

            $target = $template ?? new CommunicationTemplate(['key' => $key]);
            $isNew = ! $target->exists;
            $beforeVersion = $target->version;

            $target->forceFill([
                'name' => trim($data['name']),
                'channel' => $data['channel'],
                'subject' => ! empty($data['subject']) ? trim($data['subject']) : null,
                'dlt_template_id' => ! empty($data['dlt_template_id']) ? trim($data['dlt_template_id']) : null,
                'body' => trim($data['body']),
                'variables' => $data['variables'] ?? null,
                'version' => $isNew ? 1 : $beforeVersion + 1,
            ]);
            $target->save();

            $this->audit->record(
                $isNew ? 'comms.template_created' : 'comms.template_updated',
                $target,
                after: ['key' => $target->key, 'version' => $target->version],
                actor: $admin,
                subjectLabel: $target->key,
            );

            return $target;
        });
    }
}
