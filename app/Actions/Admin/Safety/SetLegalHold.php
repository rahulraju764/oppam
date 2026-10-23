<?php

declare(strict_types=1);

namespace App\Actions\Admin\Safety;

use App\Models\AbuseCase;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Place or lift a legal hold on the member of an A10 case (PRD A10 "legal hold (super admin)",
 * decisions 2026-10-23): `profiles.legal_hold_at`. While held, the member purge, chat pruning
 * and ID-document purge skip this profile, so evidence survives a legal request. Super admins
 * only; typed reason (5 – 500); audited. A repeat changes nothing.
 */
final class SetLegalHold
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws AuthorizationException|ValidationException */
    public function handle(AdminUser $admin, AbuseCase $case, bool $hold, string $reason): void
    {
        if (! $admin->isSuperAdmin()) {
            throw new AuthorizationException(__('Only a super admin can place or lift a legal hold.'));
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of 5 to 500 characters (it is recorded in the audit log).')]);
        }

        DB::transaction(function () use ($admin, $case, $hold, $reason): void {
            $profile = Profile::withTrashed()->whereKey($case->reported_profile_id)->lockForUpdate()->firstOrFail();
            if (($profile->legal_hold_at !== null) === $hold) {
                return;
            }

            $before = $profile->legal_hold_at?->toIso8601String();
            $profile->legal_hold_at = $hold ? now() : null;
            $profile->save();

            $this->audit->record($hold ? 'safety.legal_hold.placed' : 'safety.legal_hold.lifted', $case,
                ['legal_hold_at' => $before], ['legal_hold_at' => $profile->legal_hold_at?->toIso8601String()],
                reason: $reason, actor: $admin, subjectLabel: $profile->code);
        });
    }
}
