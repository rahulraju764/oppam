<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdminSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed-in admin session (A01 session list & revoke). Its id is stored in that admin's
 * server-side session; EnsureAdminSessionIsValid checks it on every request.
 *
 * @property string $id
 * @property string $admin_user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
final class AdminSession extends Model
{
    /** @use HasFactory<AdminSessionFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @param Builder<self> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
