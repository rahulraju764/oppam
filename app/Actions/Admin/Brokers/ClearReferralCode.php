<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ReviewsReferralCodes;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A07 unverified codes → "clear" (B.12): the typed code matches no bureau worth linking (noise, a
 * test, a bureau that was rejected). The raw code is removed from the profile; the audit row keeps
 * it with the reason (R-M13-3). The member is not told — nothing changed for them.
 */
final class ClearReferralCode
{
    use ReviewsReferralCodes;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Profile $profile, string $reason): void
    {
        $this->authorizeReview($admin);
        $reason = $this->validatedReason($reason);

        DB::transaction(function () use ($admin, $profile, $reason): void {
            $profile = $this->lockUnverified($profile);
            $raw = $profile->broker_code_raw;

            $profile->forceFill(['broker_code_raw' => null])->save();

            $this->audit->record('brokers.referral_cleared', $profile, ['broker_code_raw' => $raw], ['broker_code_raw' => null],
                reason: $reason, actor: $admin, subjectLabel: $profile->code);
        });
    }
}
