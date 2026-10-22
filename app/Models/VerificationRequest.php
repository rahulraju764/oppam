<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationDocType;
use App\Enums\VerificationFlag;
use App\Enums\VerificationRejectReason;
use App\Enums\VerificationStatus;
use Database\Factories\VerificationRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One ID submission (PRD M09 / A05). Created only by SubmitVerification, decided only by the A05
 * Actions — nothing is mass-assignable. The number itself is never stored: `number_last4` (shown
 * masked) and `number_hash` (HMAC, duplicate checks) only. `flags` holds the automatic fraud
 * signals computed at submission: list of {code, detail}.
 *
 * @property string $id
 * @property string $profile_id
 * @property VerificationDocType $doc_type
 * @property string $number_last4
 * @property string $number_hash
 * @property string|null $name_on_document
 * @property VerificationStatus $status
 * @property list<array{code: string, detail: string}>|null $flags
 * @property bool $is_priority
 * @property Carbon $submitted_at
 * @property Carbon $consented_at
 * @property string|null $claimed_by_admin_id
 * @property Carbon|null $claimed_until
 * @property string|null $decided_by_admin_id
 * @property Carbon|null $decided_at
 * @property VerificationRejectReason|null $reject_reason
 * @property string|null $member_note
 * @property string|null $escalation_note
 * @property Carbon|null $revoked_at
 * @property Carbon|null $documents_purged_at
 * @property Carbon $created_at
 * @property-read Profile|null $profile
 * @property-read AdminUser|null $claimedBy
 * @property-read AdminUser|null $decidedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VerificationDocument> $documents
 */
final class VerificationRequest extends Model
{
    /** @use HasFactory<VerificationRequestFactory> */
    use HasFactory, HasUlids;

    /** The key carried by AdminQueueCountChanged / ModerationClaimChanged (sidebar badge, A02 card). */
    public const QUEUE_KEY = 'verification';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'doc_type' => VerificationDocType::class,
            'status' => VerificationStatus::class,
            'reject_reason' => VerificationRejectReason::class,
            'flags' => 'array',
            'is_priority' => 'boolean',
            'submitted_at' => 'datetime',
            'consented_at' => 'datetime',
            'claimed_until' => 'datetime',
            'decided_at' => 'datetime',
            'revoked_at' => 'datetime',
            'documents_purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withTrashed();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'claimed_by_admin_id');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }

    /** @return HasMany<VerificationDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VerificationDocument::class)->orderBy('side', 'desc');   // FRONT before BACK
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', VerificationStatus::open());
    }

    /**
     * Waiting for an officer: open, and the member's account is still there (a deleted member's
     * request leaves the queue; restoring the account brings it back).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->open()->whereHas('profile', fn (Builder $q) => $q->whereNull('profiles.deleted_at'));
    }

    /** The A05 badge number: requests waiting, escalated ones included. */
    public static function waitingCount(): int
    {
        return self::query()->waiting()->count();
    }

    public function maskedNumber(): string
    {
        return $this->doc_type->masked($this->number_last4);
    }

    public function isClaimedByOther(AdminUser $admin): bool
    {
        return $this->claimed_by_admin_id !== null && $this->claimed_by_admin_id !== $admin->id
            && $this->claimed_until?->isFuture() === true;
    }

    /** @return list<VerificationFlag> */
    public function flagCodes(): array
    {
        return collect($this->flags ?? [])
            ->map(fn (array $flag): ?VerificationFlag => VerificationFlag::tryFrom($flag['code']))
            ->filter()->values()->all();
    }

    /** What the member reads about a rejection: the reason's guidance, then the officer's note. */
    public function memberGuidance(): ?string
    {
        if ($this->reject_reason === null) {
            return null;
        }

        return trim($this->reject_reason->memberMessage().' '.($this->member_note ?? ''));
    }
}
