<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\RejectReason;
use App\Enums\WizardStep;
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

    /**
     * What the member reads about their latest reject / request-changes decision (R-M02-5): the
     * category's member message, then the moderator's note.
     */
    public static function latestDecisionNote(Profile $profile): ?string
    {
        $item = self::latestDecision($profile);

        if ($item === null) {
            return null;
        }

        $parts = array_filter([
            $item->reason_category !== null ? RejectReason::tryFrom($item->reason_category)?->memberMessage() : null,
            $item->reason_note,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** The wizard step the moderator asked the member to fix (deep link), default step 1. */
    public static function latestDecisionStep(Profile $profile): WizardStep
    {
        $step = self::latestDecision($profile)?->fields['step'] ?? null;

        return WizardStep::tryFrom(is_numeric($step) ? (int) $step : 1) ?? WizardStep::Basic;
    }

    private static function latestDecision(Profile $profile): ?self
    {
        return self::query()
            ->where('profile_id', $profile->id)
            ->ofType(ModerationItemType::ProfileNew)
            ->whereIn('status', [ModerationStatus::Rejected, ModerationStatus::ChangesRequested])
            ->latest('decided_at')
            ->first();
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'claimed_by_admin_id');
    }

    /** Someone else holds a live claim on this item. */
    public function isClaimedByOther(AdminUser $admin): bool
    {
        return $this->claimed_by_admin_id !== null
            && $this->claimed_by_admin_id !== $admin->id
            && $this->claimed_until?->isFuture() === true;
    }

    /**
     * A short hash of the held content (`fields`). The screen sends back the hash of what the
     * moderator saw, so a decision never covers content that changed after it was shown.
     */
    public function fieldsFingerprint(): string
    {
        return hash('sha256', (string) json_encode($this->fields));
    }
}
