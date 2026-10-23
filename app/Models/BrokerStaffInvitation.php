<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BureauRole;
use App\Enums\ProfileScope;
use Database\Factories\BrokerStaffInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation for a staff login (B.8; screens in P7.5). Only the token HASH is stored. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $broker_id
 * @property string $phone
 * @property string $name
 * @property BureauRole $role
 * @property ProfileScope $profile_scope
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property-read Broker|null $broker
 */
final class BrokerStaffInvitation extends Model
{
    /** @use HasFactory<BrokerStaffInvitationFactory> */
    use HasFactory, HasUlids;

    protected $table = 'broker_staff_invitations';

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => BureauRole::class,
            'profile_scope' => ProfileScope::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }
}
