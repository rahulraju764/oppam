<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditActorType;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use App\Queries\Audit\AuditLogQuery;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way an audit row is written (PRD A12, CLAUDE.md security rule 8). Every admin write
 * goes through an Action that calls record(); the actor defaults to the signed-in admin.
 * before/after are redacted: secrets are removed, contact details masked.
 */
final class AuditLogger
{
    /** Keys whose values never reach the audit trail. */
    private const SECRET_KEYS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'token_hash', 'code_hash', 'session_hash', 'secret'];

    /** Keys whose values are masked to their last 4 characters. */
    private const MASKED_KEYS = ['phone', 'alternate_phone', 'mobile'];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        AdminUser|User|null $actor = null,
        ?string $subjectLabel = null,
    ): AuditLog {
        $actor ??= $this->auth->guard('admin')->user();

        $log = new AuditLog;
        $log->forceFill([
            'actor_type' => match (true) {
                $actor instanceof AdminUser => AuditActorType::Admin,
                $actor instanceof User => AuditActorType::User,
                default => AuditActorType::System,
            },
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor instanceof AdminUser ? $actor->email : null,
            'action' => $action,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'subject_label' => $subjectLabel,
            'before' => $before === [] ? null : $this->redact($before),
            'after' => $after === [] ? null : $this->redact($after),
            'reason' => $reason !== null ? Str::limit($reason, 500, '') : null,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 255, ''),
        ])->save();

        // After the commit (a render in the transaction window would re-cache the list without the new value for a day),
        // and rescued (a cache outage must never roll back the admin action being audited) — P9.3c review.
        DB::afterCommit(fn () => rescue(fn () => $this->forgetStaleFilterLists($log), report: false));

        return $log;
    }

    /**
     * The A12 viewer's area / subject-type filters are cached for a day (AuditLogQuery, P9.3c): a row with
     * an area or type the cached list doesn't have yet makes the list re-read on the next view. Two cache
     * reads per write; nothing when the lists aren't cached.
     */
    private function forgetStaleFilterLists(AuditLog $log): void
    {
        $areas = Cache::get(AuditLogQuery::AREAS_KEY);
        if (is_array($areas) && ! in_array(explode('.', $log->action)[0], $areas, true)) {
            Cache::forget(AuditLogQuery::AREAS_KEY);
        }

        $types = Cache::get(AuditLogQuery::SUBJECT_TYPES_KEY);
        if ($log->subject_type !== null && is_array($types) && ! in_array($log->subject_type, $types, true)) {
            Cache::forget(AuditLogQuery::SUBJECT_TYPES_KEY);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function redact(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = match (true) {
                in_array($key, self::SECRET_KEYS, true) => '[redacted]',
                in_array($key, self::MASKED_KEYS, true) && is_string($value) => '••••'.substr($value, -4),
                is_array($value) => $this->redact($value),
                default => $value,
            };
        }

        return $redacted;
    }
}
