<?php

declare(strict_types=1);

namespace App\Actions\Admin\Verification\Concerns;

use App\Enums\VerificationStatus;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\VerificationRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules for every A05 decision (same shape as A04): `verification.approve` is required,
 * ESCALATED requests are decided by super admins only, the request must still wait, and the
 * officer must hold its claim — taken here atomically when free, so two officers can never decide
 * the same request.
 */
trait DecidesVerificationRequests
{
    /** @throws AuthorizationException */
    private function authorizeDecision(AdminUser $admin, VerificationRequest $request): void
    {
        Gate::forUser($admin)->authorize('verification.approve');

        if ($request->status === VerificationStatus::Escalated && ! $admin->isSuperAdmin()) {
            throw new AuthorizationException(__('Escalated requests are decided by a super admin.'));
        }
    }

    /**
     * Take (or renew) the claim with one conditional UPDATE — free, expired or already ours — then
     * read it back (MySQL reports 0 changed rows when renewing within the same second).
     *
     * @throws VerificationRequestUnavailable
     * @throws AuthorizationException
     */
    private function holdClaim(AdminUser $admin, VerificationRequest $request): void
    {
        VerificationRequest::query()
            ->whereKey($request->id)
            ->open()
            ->where(fn ($q) => $q->whereNull('claimed_by_admin_id')
                ->orWhere('claimed_until', '<', now())
                ->orWhere('claimed_by_admin_id', $admin->id))
            ->update([
                'claimed_by_admin_id' => $admin->id,
                'claimed_until' => now()->addMinutes((int) config('oppam.verification.claim_minutes')),
                'updated_at' => now(),
            ]);

        $fresh = $request->fresh();

        if ($fresh === null || ! $fresh->status->isOpen()) {
            throw VerificationRequestUnavailable::alreadyDecided();
        }

        if ($fresh->claimed_by_admin_id !== $admin->id) {
            throw VerificationRequestUnavailable::claimedByOther();
        }

        if ($fresh->status === VerificationStatus::Escalated && ! $admin->isSuperAdmin()) {
            throw new AuthorizationException(__('Escalated requests are decided by a super admin.'));
        }

        $request->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * Inside the decision's transaction: the request row (locked) is still open and ours, and the
     * member's profile still exists; returns the locked profile.
     *
     * @throws VerificationRequestUnavailable
     */
    private function lockForDecision(AdminUser $admin, VerificationRequest $request): Profile
    {
        $current = VerificationRequest::query()->whereKey($request->id)->lockForUpdate()->first();

        if ($current === null || ! $current->status->isOpen()) {
            throw VerificationRequestUnavailable::alreadyDecided();
        }

        if ($current->claimed_by_admin_id !== $admin->id) {
            throw VerificationRequestUnavailable::claimedByOther();
        }

        $profile = Profile::query()->whereKey($current->profile_id)->lockForUpdate()->first();

        if ($profile === null) {
            throw VerificationRequestUnavailable::memberGone();
        }

        $request->setRawAttributes($current->getAttributes(), true);

        return $profile;
    }

    /**
     * A trimmed note of at most 500 characters; required (≥ 5) when $required. Empty → null.
     *
     * @throws ValidationException
     */
    private function validatedNote(?string $note, bool $required, string $field = 'note'): ?string
    {
        $note = trim((string) $note);

        if ($required && mb_strlen($note) < 5) {
            throw ValidationException::withMessages([$field => __('Write a note of at least 5 characters.')]);
        }

        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessages([$field => __('Keep the note under 500 characters.')]);
        }

        return $note === '' ? null : $note;
    }

    /** @param  array<string, mixed>  $fields */
    private function decide(VerificationRequest $request, AdminUser $admin, VerificationStatus $status, array $fields = []): void
    {
        $request->forceFill([
            'status' => $status,
            'decided_by_admin_id' => $admin->id,
            'decided_at' => now(),
            'claimed_by_admin_id' => null,
            'claimed_until' => null,
            ...$fields,
        ])->save();
    }
}
