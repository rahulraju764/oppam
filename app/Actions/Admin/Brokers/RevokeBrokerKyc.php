<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Enums\BrokerKycStatus;
use App\Enums\BureauRole;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Notifications\Broker\BrokerKycDecided;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 "KYC revocation" (§11A B.12, R-M13-21, B.19 "KYC revoked later"): a verified bureau's KYC
 * becomes REVOKED. The bureau stays ACTIVE and keeps its profiles, referrals and logins, but
 * BureauGate refuses create, import and export for the Owner and every staff login from the very
 * next request (nothing is cached), and payout runs leave it out (R-A07-2). The Owner may send KYC
 * again (the usual A07 review). Needs `brokers.kyc`; typed reason, audited; the Owner is told
 * (without the reason).
 */
final class RevokeBrokerKyc
{
    use ManagesBureaus;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BrokerStateConflict KYC isn't VERIFIED
     */
    public function handle(AdminUser $admin, Broker $broker, string $reason): Broker
    {
        $this->authorizeFor($admin, 'brokers.kyc');
        $reason = $this->validatedReason($reason);

        $locked = DB::transaction(function () use ($admin, $broker, $reason): Broker {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            if ($locked->kyc_status !== BrokerKycStatus::Verified) {
                throw BrokerStateConflict::kycNotVerified();
            }

            $locked->forceFill(['kyc_status' => BrokerKycStatus::Revoked])->save();
            $this->audit->record('brokers.kyc_revoked', $locked, ['kyc_status' => BrokerKycStatus::Verified->value],
                ['kyc_status' => BrokerKycStatus::Revoked->value], reason: $reason, actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });

        BrokerStaff::query()->where('broker_id', $locked->id)->where('role', BureauRole::Owner)->with('user')->first()
            ?->user?->notify(new BrokerKycDecided(BrokerKycDecided::REVOKED));

        return $locked;
    }
}
