<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Domain\Comms\TemplateCopy;
use App\Enums\CommsChannel;
use App\Enums\NotificationEvent;
use App\Models\AdminUser;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A14 template manager (P8.3a, `templates.edit`): save the copy of one notification event on one
 * channel — or a reusable campaign text — as a NEW version (versions are never edited); the new
 * version becomes live. Channel rules: email needs a subject (Markdown body); SMS needs the DLT
 * template id and fits 2 segments (the body is the DLT-registered text, for reference and tests);
 * in-app's first line is the title. Only the allow-listed {placeholders} (TemplateCopy).
 * Audited with the version number — never the text.
 */
final class SaveTemplate
{
    public const MAX_SMS_LENGTH = 320;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TemplateCopy $copy,
    ) {}

    /**
     * @param  string  $event  a member NotificationEvent value, or CommunicationTemplate::CAMPAIGN_EVENT
     * @param  string|null  $key  campaign texts only (lower-case, a–z 0–9 _)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, string $event, CommsChannel $channel, ?string $subject, string $body, ?string $dltTemplateId,
        ?string $name = null, ?string $key = null): CommunicationTemplate
    {
        Gate::forUser($admin)->authorize('templates.edit');

        [$key, $name] = $this->identity($event, $channel, $key, $name);
        [$subject, $body, $dltTemplateId] = $this->validated($channel, $subject, $body, $dltTemplateId);

        $template = DB::transaction(function () use ($admin, $event, $channel, $key, $name, $subject, $body, $dltTemplateId): CommunicationTemplate {
            $template = CommunicationTemplate::query()->where('key', $key)->lockForUpdate()->first();
            $isNew = $template === null;

            if ($template !== null && ($template->event !== $event || $template->channel !== $channel)) {
                throw ValidationException::withMessages(['key' => __('A template with this key already exists.')]);
            }

            $template ??= (new CommunicationTemplate)->forceFill(['key' => $key, 'event' => $event, 'channel' => $channel, 'current_version' => 0]);
            $next = $isNew ? 1 : (int) CommunicationTemplateVersion::query()->where('template_id', $template->id)->max('version') + 1;

            $template->forceFill(['name' => $name, 'is_active' => true, 'current_version' => $next])->save();

            (new CommunicationTemplateVersion)->forceFill([
                'template_id' => $template->id,
                'version' => $next,
                'subject' => $subject,
                'body' => $body,
                'dlt_template_id' => $dltTemplateId,
                'created_by_admin_id' => $admin->id,
                'created_at' => now(),
            ])->save();

            $this->audit->record($isNew ? 'comms.template_created' : 'comms.template_updated', $template,
                after: ['key' => $key, 'version' => $next], actor: $admin, subjectLabel: $key);

            return $template;
        });

        $this->copy->forget($event, $channel);

        return $template;
    }

    /**
     * @return array{string, string}
     *
     * @throws ValidationException
     */
    private function identity(string $event, CommsChannel $channel, ?string $key, ?string $name): array
    {
        if ($event === CommunicationTemplate::CAMPAIGN_EVENT) {
            $key = mb_strtolower(trim((string) $key));
            if (preg_match('/^[a-z0-9_]{3,60}$/', $key) !== 1 || str_contains($key, '.')) {
                throw ValidationException::withMessages(['key' => __('Use 3–60 lower-case letters, numbers or underscores.')]);
            }
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 100) {
                throw ValidationException::withMessages(['name' => __('Give the template a name (up to 100 characters).')]);
            }

            return ['campaign.'.$key, $name];
        }

        $known = NotificationEvent::tryFrom($event);
        if ($known === null || ! $known->isForMembers() || ! $known->usesChannel($channel->value)) {
            throw ValidationException::withMessages(['event' => __('This notification isn’t sent on that channel.')]);
        }

        return [CommunicationTemplate::keyFor($event, $channel), $known->label().' — '.$channel->label()];
    }

    /**
     * @return array{?string, string, ?string}
     *
     * @throws ValidationException
     */
    private function validated(CommsChannel $channel, ?string $subject, string $body, ?string $dltTemplateId): array
    {
        $subject = $subject !== null && trim($subject) !== '' ? trim($subject) : null;
        $body = trim(str_replace("\r\n", "\n", $body));
        $dltTemplateId = $dltTemplateId !== null && trim($dltTemplateId) !== '' ? trim($dltTemplateId) : null;
        $errors = [];

        if ($body === '' || mb_strlen($body) > 5000) {
            $errors['body'] = __('Write the message (up to 5,000 characters).');
        }
        if (($unknown = TemplateCopy::unknownPlaceholders($body.' '.$subject)) !== []) {
            $errors['body'] = __('Unknown placeholder: :list. Use only the ones listed.', ['list' => '{'.implode('}, {', $unknown).'}']);
        }

        match ($channel) {
            CommsChannel::Email => $subject === null || mb_strlen($subject) > 200
                ? $errors['subject'] = __('An email needs a subject (up to 200 characters).') : null,
            CommsChannel::Sms => (function () use (&$errors, $body, $dltTemplateId): void {
                if ($dltTemplateId === null || preg_match('/^[0-9A-Za-z]{6,50}$/', $dltTemplateId) !== 1) {
                    $errors['dltTemplateId'] = __('An SMS needs its DLT template id (from the DLT portal).');
                }
                if (mb_strlen($body) > self::MAX_SMS_LENGTH) {
                    $errors['body'] = __('An SMS fits :max characters.', ['max' => self::MAX_SMS_LENGTH]);
                }
            })(),
            CommsChannel::InApp => mb_strlen(strtok($body, "\n") ?: '') > 150
                ? $errors['body'] = __('The first line is the title: keep it to 150 characters.') : null,
        };

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            $channel === CommsChannel::Email ? $subject : null,
            $body,
            $channel === CommsChannel::Sms ? $dltTemplateId : null,
        ];
    }
}
