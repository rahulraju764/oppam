<?php

declare(strict_types=1);

namespace App\Actions\Admin\Verification;

use App\Enums\VerificationStatus;
use App\Events\Verification\VerificationChanged;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\VerificationRequest;
use App\Notifications\Verification\VerificationRejectedNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A03 "force re-verification" (P1.7a → P6.1): take the Verified badge back — the approved request
 * becomes REVOKED, `is_verified` off — so the member must send an ID again. Needs
 * `verification.approve` and a typed reason (5–500, audited, never shown to the member). The
 * member is told to verify again and their open pages update live.
 */
final class RevokeVerification
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Profile $profile, string $reason): void
    {
        Gate::forUser($admin)->authorize('verification.approve');

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['revokeReason' => __('Give a reason of 5 to 500 characters (it is recorded in the audit log).')]);
        }

        $revoked = DB::transaction(function () use ($admin, $profile, $reason): bool {
            $locked = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_verified) {
                return false;
            }

            VerificationRequest::query()->where('profile_id', $locked->id)->where('status', VerificationStatus::Approved)
                ->update(['status' => VerificationStatus::Revoked->value, 'revoked_at' => now(), 'updated_at' => now()]);
            $locked->forceFill(['is_verified' => false])->save();

            $this->audit->record('verification.revoked', $locked, ['is_verified' => true], ['is_verified' => false],
                reason: $reason, actor: $admin, subjectLabel: $locked->code);

            if ($locked->user_id !== null) {
                VerificationChanged::dispatch($locked->user_id, VerificationStatus::Revoked->value, false);
            }

            return true;
        });

        if (! $revoked) {
            throw ValidationException::withMessages(['revokeReason' => __('This member is not verified.')]);
        }

        $profile->loadMissing('user')->user?->notify(new VerificationRejectedNotification(
            __('We need to check your ID again. Please upload a current government ID from the Verify page.'), revoked: true,
        ));
    }
}
