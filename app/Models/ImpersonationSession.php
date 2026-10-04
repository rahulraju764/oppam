<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImpersonationEndReason;
use Database\Factories\ImpersonationSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One admin impersonation of a member (A01 / A03, P1.7b). Written only by the Impersonation
 * Actions.
 *
 * @property string $id
 * @property string|null $admin_user_id
 * @property string $admin_label
 * @property string $user_id
 * @property string $reason
 * @property string|null $token_hash
 * @property Carbon|null $token_expires_at
 * @property string|null $ip_address
 * @property Carbon|null $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $ended_at
 * @property ImpersonationEndReason|null $end_reason
 * @property Carbon $created_at
 */
final class ImpersonationSession extends Model
{
    /** @use HasFactory<ImpersonationSessionFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => ImpersonationEndReason::class,
        ];
    }

    /**
     * Not ended, and either waiting for its handoff or inside its 30 minutes.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at')->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->whereNull('started_at')->where('token_expires_at', '>', now()))
            ->orWhere(fn (Builder $w) => $w->whereNotNull('started_at')->where('expires_at', '>', now())));
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null && $this->started_at !== null && $this->expires_at?->isFuture() === true;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
