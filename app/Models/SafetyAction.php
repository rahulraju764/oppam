<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CaseOutcome;
use Database\Factories\SafetyActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One safety outcome applied to a member (PRD §7.1 `safety_actions`, A10): written once by
 * DecideAbuseCase and never changed — the member's safety history on later cases. `details`
 * holds counts only (messages removed, photos rejected, identifiers banned), never content.
 *
 * @property string $id
 * @property string|null $abuse_case_id
 * @property string $profile_id
 * @property string|null $admin_id
 * @property CaseOutcome $outcome
 * @property string|null $note
 * @property array<string, int>|null $details
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read AdminUser|null $admin
 */
final class SafetyAction extends Model
{
    /** @use HasFactory<SafetyActionFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        // Append-only, like audit_logs: an outcome is history.
        self::updating(static fn (): never => throw new LogicException('Safety actions are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Safety actions are append-only.'));
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => CaseOutcome::class,
            'details' => 'array',
        ];
    }
}
