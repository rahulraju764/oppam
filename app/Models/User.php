<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreatedFor;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A member or broker account on the web guard (PRD §7.2, §8.1). The mobile number is the login
 * id. role and status are never mass-assignable — they are set by Actions only.
 *
 * @property string $id
 * @property string $phone
 * @property string|null $email
 * @property UserRole $role
 * @property CreatedFor $created_for
 * @property UserStatus $status
 * @property \Illuminate\Support\Carbon|null $phone_verified_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property int $session_epoch bumped by "log out other devices" / password reset (P1.1)
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'phone',
        'email',
        'password',
        'created_for',
    ];

    /** Mirrors the column default, so a user created in this request has it without a reload. */
    protected $attributes = [
        'session_epoch' => 0,
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'created_for' => CreatedFor::class,
            'status' => UserStatus::class,
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'session_epoch' => 'integer',
            'password' => 'hashed',
        ];
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** @return HasMany<NotificationPreference, $this> */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /** @return HasMany<LoginEvent, $this> */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }
}
