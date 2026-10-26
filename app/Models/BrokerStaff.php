<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ProfileScope;
use App\Services\Broker\StaffCodeGenerator;
use App\ValueObjects\PhoneNumber;
use Database\Factories\BrokerStaffFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bureau login (PRD §11A B.2 / B.8): which bureau a BROKER user acts for, in which role, with
 * which profile scope and which of the role's permissions the Owner switched off. One bureau per
 * login (unique user_id, R-M13-22). Read through BureauGate; never mass-assignable.
 *
 * @property string $id
 * @property string $code STF1001 — the public id (URLs never carry ULIDs), system-generated
 * @property string $broker_id
 * @property string $user_id
 * @property string|null $name as typed by the Owner on the invitation (P7.5)
 * @property BureauRole $role
 * @property ProfileScope $profile_scope
 * @property list<string>|null $disabled_permissions
 * @property BureauStaffStatus $status
 * @property string|null $invited_by_user_id
 * @property Carbon|null $last_login_at
 * @property-read Broker|null $broker
 * @property-read User|null $user
 */
final class BrokerStaff extends Model
{
    /** @use HasFactory<BrokerStaffFactory> */
    use HasFactory, HasUlids;

    protected $table = 'broker_staff';

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        // Every login gets its public code at creation, whoever creates it (application, invitation, seeders).
        self::creating(static function (self $staff): void {
            if (($staff->code ?? '') === '') {
                $staff->code = app(StaffCodeGenerator::class)->next();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => BureauRole::class,
            'profile_scope' => ProfileScope::class,
            'status' => BureauStaffStatus::class,
            'disabled_permissions' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Profile, $this> the bureau's clients assigned to this login */
    public function assignedProfiles(): HasMany
    {
        return $this->hasMany(Profile::class, 'assigned_staff_id')->whereNull('claimed_at');
    }

    /**
     * How the bureau sees this login in lists (assign menus, note authors): the Owner shows the bureau
     * owner's name, staff the name from their invitation (P7.5), or else their role and masked mobile.
     * Needs `broker` / `user` loaded for the fallbacks.
     */
    public function displayName(): string
    {
        if ($this->isOwner() && $this->broker !== null) {
            return $this->broker->name;
        }

        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        $phone = $this->user !== null ? PhoneNumber::tryParse($this->user->phone)?->masked() : null;

        return trim($this->role->label().' '.($phone ?? ''));
    }

    /**
     * Sees only the clients assigned to them (R-M13-20): "assigned only" staff, and every Telecaller
     * whatever the column says — B.2 gives Telecallers `profiles.view` on assigned profiles only, a
     * ceiling no scope setting can widen (R-M13-19).
     */
    public function isAssignedOnly(): bool
    {
        return $this->profile_scope === ProfileScope::Assigned || $this->role === BureauRole::Telecaller;
    }

    /** The scope that is actually enforced (a Telecaller is assigned-only whatever the column says). */
    public function effectiveScope(): ProfileScope
    {
        return $this->isAssignedOnly() ? ProfileScope::Assigned : ProfileScope::All;
    }

    /**
     * The scopes an Owner may choose for this role (B.2: Telecallers only "assigned").
     *
     * @return array<string, string> value => label
     */
    public static function scopeOptionsFor(BureauRole $role): array
    {
        return $role === BureauRole::Telecaller
            ? [ProfileScope::Assigned->value => ProfileScope::Assigned->label()]
            : ProfileScope::options();
    }

    public function isOwner(): bool
    {
        return $this->role === BureauRole::Owner;
    }
}
