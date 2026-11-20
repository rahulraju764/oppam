<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Data\Audit\AuditFilterData;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Queries\Audit\AuditLogQuery;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Export the filtered A12 audit trail as CSV (P9.2a). Needs `audit.export` (a refusal is audited
 * and logged). At most `system.audit_export_max_rows` rows — a larger selection is refused with
 * "narrow the filters" (no background export: the trail is read straight from its indexes).
 * EXPORTS_PER_HOUR per admin. The export itself is audited, with the filters and the row count,
 * before the first byte is sent.
 */
final class ExportAuditLog
{
    public const EXPORTS_PER_HOUR = 10;

    public function __construct(
        private readonly AuditLogQuery $query,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
    ) {}

    /** @throws AuthorizationException */
    public function authorize(AdminUser $admin): void
    {
        if (Gate::forUser($admin)->denies('audit.export')) {
            $this->audit->record('audit.export_denied', null, actor: $admin);
            Log::warning('Audit export refused: missing audit.export', ['admin_id' => $admin->id]);

            throw new AuthorizationException(__('You do not have permission to export the audit log.'));
        }
    }

    /**
     * Check the request (permission, rate limit, size). Returns the row count.
     *
     * @throws AuthorizationException|ValidationException|TooManyRequestsHttpException
     */
    public function prepare(AdminUser $admin, AuditFilterData $filters): int
    {
        $this->authorize($admin);

        $built = $this->query->build($filters);
        if ($built['unknown'] !== []) {
            throw ValidationException::withMessages(['export' => __('Fix the filters first: something in them matches nothing.')]);
        }

        $rows = $built['query']->count();
        $max = Settings::int(SettingKey::SystemAuditExportMaxRows);
        if ($rows === 0) {
            throw ValidationException::withMessages(['export' => __('Nothing to export: no audit row matches these filters.')]);
        }
        if ($rows > $max) {
            throw ValidationException::withMessages(['export' => __('That is :rows rows — more than :max can be exported at once. Narrow the filters (a shorter date range, an area or a subject).', [
                'rows' => number_format($rows), 'max' => number_format($max),
            ])]);
        }

        $bucket = 'audit-export:'.$admin->id;
        if ($this->limiter->tooManyAttempts($bucket, self::EXPORTS_PER_HOUR)) {
            throw new TooManyRequestsHttpException($this->limiter->availableIn($bucket), __('You have exported the audit log several times this hour. Please try again later.'));
        }
        $this->limiter->hit($bucket, 3600);

        return $rows;
    }

    /** Audit the download (called by the controller before streaming). */
    public function streamed(AdminUser $admin, AuditFilterData $filters, int $rows): void
    {
        $this->audit->record('audit.exported', null, after: [
            'filters' => array_filter($filters->toArray(), fn (string $v): bool => $v !== ''),
            'rows' => $rows,
        ], actor: $admin);
    }
}
