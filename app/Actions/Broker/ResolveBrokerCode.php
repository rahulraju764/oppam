<?php

declare(strict_types=1);

namespace App\Actions\Broker;

use App\Domain\Broker\BrokerCodeLookup;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Models\BrokerReferral;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * Attribute a member's profile to a bureau from a referral code (B.5 ResolveBrokerCode), at
 * registration and at wizard step 1:
 *
 * - the typed code wins over the `?ref=` cookie (R-M13-9); both are normalised (R-M13-2);
 * - FOUND + ACTIVE bureau → profiles.broker_id, broker_verified = true and a PENDING ledger row;
 * - an unknown code or a bureau that isn't ACTIVE → only broker_code_raw (broker_verified = false),
 *   for the A07 unverified-codes queue;
 * - it never blocks or fails a registration (R-M13-1) and never replaces a verified attribution —
 *   attribution is first-touch and permanent, changed by admins only (R-M13-3).
 *
 * Called inside the caller's transaction (the profile was just written there).
 */
final class ResolveBrokerCode
{
    public function __construct(private readonly BrokerCodeLookup $lookup) {}

    public function handle(Profile $profile, ?string $typed, ?string $cookie = null): void
    {
        if ($profile->broker_verified || $profile->broker_id !== null) {
            return;
        }

        $code = BrokerCodeLookup::normalise($typed) ?? BrokerCodeLookup::normalise($cookie);

        if ($code === null) {
            return;
        }

        DB::transaction(function () use ($profile, $code): void {
            $broker = $this->lookup->activeBroker($code);

            $profile->forceFill([
                'broker_id' => $broker?->id,
                'broker_code_raw' => $code,
                'broker_verified' => $broker !== null,
            ])->save();

            if ($broker !== null && ! BrokerReferral::query()->where('profile_id', $profile->id)->exists()) {
                $referral = new BrokerReferral;
                $referral->forceFill([
                    'broker_id' => $broker->id,
                    'profile_id' => $profile->id,
                    'source' => ReferralSource::Referral,
                    'status' => ReferralStatus::Pending,
                ])->save();
            }
        });
    }
}
