<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ProfileScope;
use App\ValueObjects\PhoneNumber;
use Database\Factories\BrokerStaffFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bureau login (PRD §11A B.2 / B.8): which bureau a BROKER user acts for, in which role, with
 * which profile scope and which of the role's permissions the Owner switched off. One bureau per
 * login (unique user_id, R-M13-22). Read through BureauGate; never mass-assignable.
 *
 * @property string $id
 * @property string $broker_id
 * @property string $user_id
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

    /**
     * How the bureau sees this login in lists (assign menus, note authors). Logins carry no name of
     * their own yet (P7.5 invitations add one): the Owner shows the bureau owner's name, staff their
     * role and masked mobile. Needs `broker` / `user` loaded.
     */
    public function displayName(): string
    {
        if ($this->isOwner() && $this->broker !== null) {
            return $this->broker->name;
        }

        $phone = $this->user !== null ? PhoneNumber::tryParse($this->user->phone)?->masked() : null;

        return trim($this->role->label().' '.($phone ?? ''));
    }

    public function isOwner(): bool
    {
        return $this->role === BureauRole::Owner;
    }
}
