<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Models\Masters\District;
use Database\Factories\BrokerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A marriage broker / bureau (PRD §11A). Created by Broker\ApplyAsBroker (or an admin, P7.8),
 * moved through its lifecycle only by Actions — nothing is mass-assignable; the code (BRK…) is
 * system-generated (R-A07-1). URLs use the code. The PAN typed at application is kept as a keyed
 * hash (one PAN = one bureau) and its last 4 only.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $bureau_name
 * @property string $phone
 * @property string|null $email
 * @property int|null $district_id
 * @property int $commission_bps
 * @property BrokerStatus $status
 * @property BrokerKycStatus $kyc_status
 * @property string|null $pan_hash
 * @property string|null $pan_last4
 * @property int $staff_seat_limit
 * @property int $import_daily_cap
 * @property int $interest_allowance_monthly
 * @property Carbon|null $imports_paused_at
 * @property string|null $status_reason
 * @property Carbon|null $activated_at
 * @property Carbon $created_at
 * @property-read District|null $district
 * @property-read BrokerKyc|null $latestKyc
 */
final class Broker extends Model
{
    /** @use HasFactory<BrokerFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['pan_hash'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BrokerStatus::class,
            'kyc_status' => BrokerKycStatus::class,
            'commission_bps' => 'integer',
            'staff_seat_limit' => 'integer',
            'import_daily_cap' => 'integer',
            'interest_allowance_monthly' => 'integer',
            'imports_paused_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    /** The bureau's display name: the bureau name, or the Owner's name for a one-person broker. */
    public function displayName(): string
    {
        return $this->bureau_name !== null && $this->bureau_name !== '' ? $this->bureau_name : $this->name;
    }

    /** May handle other people's data (R-M13-10, R-A07-2): ACTIVE and KYC-verified. */
    public function isVerifiedAndActive(): bool
    {
        return $this->status === BrokerStatus::Active && $this->kyc_status === BrokerKycStatus::Verified;
    }

    /**
     * APPLIED bureaus whose Owner proved the mobile: the A07 "no KYC yet" list, its count and SLA.
     * An application never confirmed by SMS is nobody's work yet (it is released later).
     *
     * @param  Builder<self>  $query
     */
    public function scopeApplicationsToReview(Builder $query): void
    {
        $query->where('status', BrokerStatus::Applied)
            ->whereHas('staff', fn (Builder $staff) => $staff->where('role', BureauRole::Owner)
                ->whereHas('user', fn (Builder $user) => $user->whereNotNull('phone_verified_at')));
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return HasMany<BrokerStaff, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(BrokerStaff::class);
    }

    /** @return HasMany<BrokerKyc, $this> */
    public function kycSubmissions(): HasMany
    {
        return $this->hasMany(BrokerKyc::class)->latest()->orderByDesc('id');
    }

    /** @return HasOne<BrokerKyc, $this> */
    public function latestKyc(): HasOne
    {
        return $this->hasOne(BrokerKyc::class)->latestOfMany();
    }
}
