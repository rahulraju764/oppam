<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use Database\Factories\ModerationItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing waiting for (or decided by) a moderator in A04. Nothing is mass-assignable: items
 * are created by member Actions (SubmitProfile, …) and decided by moderation Actions (P1.6).
 *
 * @property string $id
 * @property ModerationItemType $type
 * @property string $profile_id
 * @property string|null $subject_id
 * @property array<string, mixed>|null $fields
 * @property ModerationStatus $status
 * @property bool $is_priority
 * @property Carbon $submitted_at
 * @property string|null $claimed_by_admin_id
 * @property Carbon|null $claimed_until
 * @property string|null $decided_by_admin_id
 * @property Carbon|null $decided_at
 * @property string|null $reason_category
 * @property string|null $reason_note
 */
final class ModerationItem extends Model
{
    /** @use HasFactory<ModerationItemFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ModerationItemType::class,
            'status' => ModerationStatus::class,
            'fields' => 'array',
            'is_priority' => 'boolean',
            'submitted_at' => 'datetime',
            'claimed_until' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', ModerationStatus::pending());
    }

    /** @param  Builder<self>  $query */
    public function scopeOfType(Builder $query, ModerationItemType $type): void
    {
        $query->where('type', $type);
    }

    /** How many items of a queue are waiting — the sidebar badge number (A04, PRD §9.4). */
    public static function pendingCount(ModerationItemType $type): int
    {
        return self::query()->ofType($type)->pending()->count();
    }

    /** The moderator's note on the profile's latest reject / request-changes decision (R-M02-5). */
    public static function latestDecisionNote(Profile $profile): ?string
    {
        $note = self::query()
            ->where('profile_id', $profile->id)
            ->ofType(ModerationItemType::ProfileNew)
            ->whereIn('status', [ModerationStatus::Rejected, ModerationStatus::ChangesRequested])
            ->latest('decided_at')
            ->value('reason_note');

        return is_string($note) && $note !== '' ? $note : null;
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
