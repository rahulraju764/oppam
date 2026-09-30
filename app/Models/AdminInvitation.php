<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdminInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use staff invitation (A01): valid for 72 h, stores only the SHA-256 of the token that
 * was emailed. Created and consumed only by InviteAdmin / AcceptAdminInvitation.
 *
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string $role
 * @property string $invited_by_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
final class AdminInvitation extends Model
{
    /** @use HasFactory<AdminInvitationFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'invited_by_id');
    }
}
