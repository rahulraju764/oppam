<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

use App\Data\Settings\IntegrationStatusData;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use App\Services\Health\Probes;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A15 → Integrations "Test" button (P8.4): runs one integration's read-only check. Needs
 * settings.edit (a check calls the provider, and the mail check sends a message); at most
 * `settings.integration_checks_per_hour` checks per admin (A15 setting; rule 10 — each one is a real provider call); every check is
 * audited with its result (state + message — never a secret).
 */
final class RunIntegrationCheck
{
    public function __construct(
        private readonly Probes $probes,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AdminUser $admin, string $integration): IntegrationStatusData
    {
        Gate::forUser($admin)->authorize('settings.edit');

        $probe = $this->probes->all()[$integration] ?? throw new NotFoundHttpException;

        $bucket = 'integration-check:'.$admin->id;
        if ($this->limiter->tooManyAttempts($bucket, Settings::int(SettingKey::SettingsIntegrationChecksPerHour))) {
            throw new TooManyRequestsHttpException($this->limiter->availableIn($bucket), __('Too many checks. Please try again later.'));
        }
        $this->limiter->hit($bucket, 3600);

        $result = $probe->check($admin);

        $this->audit->record('settings.integration_checked', null, [], ['integration' => $probe->key(), 'state' => $result->state, 'message' => $result->message],
            actor: $admin, subjectLabel: $probe->key());

        return $result;
    }
}
