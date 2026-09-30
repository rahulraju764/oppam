<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminStatus;
use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff member on the admin guard (PRD §8.1, A01). Roles/permissions via spatie on the
 * `admin` guard. The TOTP secret and the recovery-code hashes are encrypted at rest and never
 * serialised. Nothing security-relevant is mass-assignable — Actions set it explicitly.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property AdminStatus $status
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int $failed_two_factor_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 */
final class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory, HasRoles, HasUlids, Notifiable;

    protected string $guard_name = 'admin';

    /** @var list<string> */
    protected $fillable = ['name', 'email'];

    /** Same defaults as the migration, so a just-created instance has every security field. */
    protected $attributes = [
        'status' => 'ACTIVE',
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'failed_two_factor_attempts' => 0,
        'locked_until' => null,
        'last_login_at' => null,
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AdminStatus::class,
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'failed_two_factor_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Staff sessions are never "remembered" (A01 idle/absolute timeouts): no remember token. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function isActive(): bool
    {
        return $this->status === AdminStatus::Active;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /** @return HasMany<AdminSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(AdminSession::class);
    }
}
