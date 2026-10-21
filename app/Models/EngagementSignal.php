<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EngagementSignalDecision;
use App\Enums\EngagementSignalStatus;
use App\Enums\EngagementSignalType;
use Database\Factories\EngagementSignalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An abuse signal for the A13 monitors (PRD A13 "Engagement abuse monitors"): written only through
 * EngagementSignals (one OPEN row per member and type — a repeat counts `occurrences`), decided by a
 * moderator on the Signals screen (DecideEngagementSignal). `details` holds counts and windows only,
 * never PII or message text.
 *
 * @property string $id
 * @property string $profile_id
 * @property EngagementSignalType $type
 * @property array<string, int|string>|null $details
 * @property EngagementSignalStatus $status
 * @property int $occurrences
 * @property \Illuminate\Support\Carbon|null $last_raised_at
 * @property EngagementSignalDecision|null $decision
 * @property string|null $decision_note
 * @property string|null $decided_by_admin_id
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Profile|null $profile
 * @property-read AdminUser|null $decidedBy
 */
final class EngagementSignal extends Model
{
    /** @use HasFactory<EngagementSignalFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** The queue key on `admin.queues` (AdminQueueCountChanged) and the sidebar badge. */
    public const QUEUE_KEY = 'chat.signals';

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
        return $query->where('status', EngagementSignalStatus::Open->value);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withTrashed();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EngagementSignalType::class,
            'details' => 'array',
            'status' => EngagementSignalStatus::class,
            'decision' => EngagementSignalDecision::class,
            'last_raised_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
