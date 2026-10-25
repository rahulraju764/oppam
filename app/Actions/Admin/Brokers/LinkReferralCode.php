<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ReviewsReferralCodes;
use App\Actions\Broker\EarnReferralCommission;
use App\Enums\BrokerStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 unverified codes → "link" (B.12, never automatic): an admin attributes a member who typed an
 * unknown / mistyped code to an ACTIVE bureau. Sets the attribution, opens the PENDING ledger row
 * and audits the change with the raw code and reason (R-M13-3). If the member is already live and
 * paid, the commission is earned now — the same rules as every trigger (EarnReferralCommission).
 */
final class LinkReferralCode
{
    use ReviewsReferralCodes;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EarnReferralCommission $commission,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Profile $profile, string $brokerCode, string $reason): void
    {
        $this->authorizeReview($admin);
        $reason = $this->validatedReason($reason);

        DB::transaction(function () use ($admin, $profile, $brokerCode, $reason): void {
            $profile = $this->lockUnverified($profile);
            $broker = Broker::query()->where('code', $brokerCode)->lockForUpdate()->first();

            if ($broker === null || $broker->status !== BrokerStatus::Active) {
                throw ValidationException::withMessages(['brokerCode' => __('Choose an active bureau.')]);
            }

            if (BrokerReferral::query()->where('profile_id', $profile->id)->exists()) {
                throw ValidationException::withMessages(['reason' => __('This profile is already on a bureau’s ledger.')]);
            }

            $raw = $profile->broker_code_raw;
            $profile->forceFill(['broker_id' => $broker->id, 'broker_verified' => true])->save();

            $referral = new BrokerReferral;
            $referral->forceFill([
                'broker_id' => $broker->id,
                'profile_id' => $profile->id,
                'source' => ReferralSource::Referral,
                'status' => ReferralStatus::Pending,
            ])->save();

            $this->audit->record('brokers.referral_linked', $profile, ['broker_code_raw' => $raw, 'broker_verified' => false],
                ['broker' => $broker->code, 'broker_verified' => true], reason: $reason, actor: $admin, subjectLabel: $profile->code);
        });

        $this->commission->handle($profile->id);
    }
}
