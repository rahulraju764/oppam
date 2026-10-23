<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AbuseCaseStatus;
use App\Enums\CaseOutcome;
use App\Enums\CasePriority;
use Database\Factories\AbuseCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An A10 abuse case (PRD A10): every report about one member while the case is OPEN — from chat
 * or from the profile page — joins it (AbuseCases). Triaged P0/P1/P2 with an SLA due time,
 * escalated to P0 at `safety.escalate_reporters` distinct reporters in `safety.escalate_window_days`.
 * Closed by DecideAbuseCase with one CaseOutcome. Admin URLs use `code` (CASE10001).
 *
 * @property string $id
 * @property string $code
 * @property string $reported_profile_id
 * @property string|null $open_key
 * @property AbuseCaseStatus $status
 * @property CasePriority $priority
 * @property \Illuminate\Support\Carbon $sla_due_at
 * @property \Illuminate\Support\Carbon|null $escalated_at
 * @property int $reports_count
 * @property int $reporters_count
 * @property \Illuminate\Support\Carbon|null $last_reported_at
 * @property string|null $assigned_admin_id
 * @property CaseOutcome|null $outcome
 * @property string|null $outcome_note
 * @property string|null $decided_by_admin_id
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $paged_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Profile|null $reported
 * @property-read AdminUser|null $assignee
 * @property-read AdminUser|null $decidedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Report> $reports
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SafetyAction> $actions
 */
final class AbuseCase extends Model
{
    /** @use HasFactory<AbuseCaseFactory> */
    use HasFactory, HasUlids;

    /** The queue key on `admin.queues` (AdminQueueCountChanged) and the sidebar badge. */
    public const QUEUE_KEY = 'safety.cases';

    /** @var list<string> */
    protected $fillable = [];

    public static function openCount(): int
    {
        return self::query()->open()->count();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AbuseCaseStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === AbuseCaseStatus::Open;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->sla_due_at->isPast();
    }

    /** @return BelongsTo<Profile, $this> */
    public function reported(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reported_profile_id')->withTrashed();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return HasMany<SafetyAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(SafetyAction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AbuseCaseStatus::class,
            'priority' => CasePriority::class,
            'outcome' => CaseOutcome::class,
            'sla_due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'last_reported_at' => 'datetime',
            'decided_at' => 'datetime',
            'paged_at' => 'datetime',
            'reports_count' => 'integer',
            'reporters_count' => 'integer',
        ];
    }
}
