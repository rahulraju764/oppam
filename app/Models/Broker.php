<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\ImportsPauseReason;
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
 * @property ImportsPauseReason|null $imports_paused_reason
 * @property string|null $imports_paused_batch_id the batch that tripped the auto-pause (R-M13-32)
 * @property string|null $status_reason
 * @property Carbon|null $activated_at
 * @property Carbon|null $payouts_held_at commission is held (A07 fraud signal) — left out of payout runs
 * @property string|null $payouts_hold_reason
 * @property string|null $created_by_admin_id set for a bureau an admin created; its Owner applies with the same mobile
 * @property Carbon|null $creation_paused_at creating and importing profiles is refused (R-A07-10: upheld consent disputes)
 * @property string|null $creation_paused_reason
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
            'imports_paused_reason' => ImportsPauseReason::class,
            'activated_at' => 'datetime',
            'payouts_held_at' => 'datetime',
            'creation_paused_at' => 'datetime',
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

    /** May be paid (R-A07-2, B.21 "KYC-less bureaus are excluded"): ACTIVE, KYC-verified and payouts not held. */
    public function isPayable(): bool
    {
        return $this->isVerifiedAndActive() && $this->payouts_held_at === null;
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

    /**
     * The commission ledger — referrals and managed conversions (B.5, B.10).
     *
     * @return HasMany<BrokerReferral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(BrokerReferral::class);
    }

    /**
     * Profiles the bureau manages now (a claimed profile has left — R-M13-16).
     *
     * @return HasMany<Profile, $this>
     */
    public function managedProfiles(): HasMany
    {
        return $this->hasMany(Profile::class, 'managed_by_broker_id');
    }

    /** @return HasMany<BrokerStaffInvitation, $this> */
    public function staffInvitations(): HasMany
    {
        return $this->hasMany(BrokerStaffInvitation::class);
    }

    /** @return HasMany<BrokerPayoutItem, $this> */
    public function payoutItems(): HasMany
    {
        return $this->hasMany(BrokerPayoutItem::class);
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

    /** @return HasMany<BrokerFraudSignal, $this> */
    public function fraudSignals(): HasMany
    {
        return $this->hasMany(BrokerFraudSignal::class);
    }

    /** @return HasMany<BrokerConsentDispute, $this> */
    public function consentDisputes(): HasMany
    {
        return $this->hasMany(BrokerConsentDispute::class);
    }
}
