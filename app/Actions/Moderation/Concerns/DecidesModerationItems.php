<?php

declare(strict_types=1);

namespace App\Actions\Moderation\Concerns;

use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Enums\RejectReason;
use App\Events\Admin\ModerationQueueChanged;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules for every A04 decision: `moderation.act` is required, ESCALATED items are decided
 * by super admins only (owner decision 2026-10-01), the item must still be waiting, and the
 * moderator must hold its claim — taken here atomically if it is free, so two moderators can
 * never decide the same item.
 */
trait DecidesModerationItems
{
    /** reason_category of items closed because the profile stopped waiting (not a moderator decision). */
    private const STALE = 'STALE';

    /** @throws AuthorizationException */
    private function authorizeDecision(AdminUser $admin, ModerationItem $item): void
    {
        Gate::forUser($admin)->authorize('moderation.act');

        if ($item->status === ModerationStatus::Escalated && ! $admin->isSuperAdmin()) {
            throw new AuthorizationException(__('Escalated items are decided by a super admin.'));
        }
    }

    /**
     * Take (or keep) the 15-minute claim with one conditional UPDATE: free, expired or already
     * ours. Anything else means another moderator holds it.
     *
     * @throws ModerationItemUnavailable
     */
    private function holdClaim(AdminUser $admin, ModerationItem $item): void
    {
        ModerationItem::query()
            ->whereKey($item->id)
            ->whereIn('status', ModerationStatus::pending())
            ->where(fn ($q) => $q->whereNull('claimed_by_admin_id')
                ->orWhere('claimed_until', '<', now())
                ->orWhere('claimed_by_admin_id', $admin->id))
            ->update([
                'claimed_by_admin_id' => $admin->id,
                'claimed_until' => now()->addMinutes((int) config('moderation.claim_minutes')),
                'updated_at' => now(),
            ]);

        // Read the claim back rather than trusting the affected-row count: MySQL counts CHANGED
        // rows, so renewing our own claim within the same second reports 0.
        $fresh = $item->fresh();

        if ($fresh === null || ! $fresh->status->isPending()) {
            throw ModerationItemUnavailable::alreadyDecided();
        }

        if ($fresh->claimed_by_admin_id !== $admin->id) {
            throw ModerationItemUnavailable::claimedByOther();
        }

        if ($fresh->status === ModerationStatus::Escalated && ! $admin->isSuperAdmin()) {
            throw new AuthorizationException(__('Escalated items are decided by a super admin.'));
        }

        $item->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * A profile that was suspended, hidden or deleted while its item waited is never flipped by
     * a moderation decision. Before deciding, such an item is closed (in its own, committed
     * transaction) and the moderator is told it is no longer waiting.
     *
     * @throws ModerationItemUnavailable
     */
    private function closeIfNoLongerWaiting(AdminUser $admin, ModerationItem $item): void
    {
        $stale = DB::transaction(function () use ($admin, $item): bool {
            // A decided item is never touched again (its decision stands).
            $current = ModerationItem::query()->whereKey($item->id)->lockForUpdate()->first();
            if ($current === null || ! $current->status->isPending()) {
                throw ModerationItemUnavailable::alreadyDecided();
            }

            $profile = Profile::query()->whereKey($item->profile_id)->lockForUpdate()->first();

            if ($profile !== null && $profile->status === ProfileStatus::PendingReview) {
                return false;
            }

            // Closed by the system, not decided by the moderator: no decided_by, a distinct
            // category (so moderator metrics never count it), and its own audit row.
            $item->forceFill([
                'status' => ModerationStatus::Rejected,
                'decided_by_admin_id' => null,
                'decided_at' => now(),
                'reason_category' => self::STALE,
                'reason_note' => __('Closed: the profile is no longer waiting for review.'),
                'claimed_by_admin_id' => null,
                'claimed_until' => null,
            ])->save();

            app(AuditLogger::class)->record('moderation.item_closed_stale', $item,
                after: ['profile_status' => $profile?->status->value ?? 'DELETED'], actor: $admin, subjectLabel: $item->type->value);

            return true;
        });

        if ($stale) {
            ModerationQueueChanged::dispatch($item->type);

            throw ModerationItemUnavailable::noLongerWaiting();
        }
    }

    /**
     * Inside the decision's transaction: lock the profile row and re-check it is still waiting
     * (guards the moment between closeIfNoLongerWaiting and the decision).
     *
     * @throws ModerationItemUnavailable
     */
    private function lockWaitingProfile(ModerationItem $item): Profile
    {
        $profile = Profile::query()->whereKey($item->profile_id)->lockForUpdate()->first();

        if ($profile === null || $profile->status !== ProfileStatus::PendingReview) {
            throw ModerationItemUnavailable::noLongerWaiting();
        }

        return $profile;
    }

    /**
     * The moderator's note: trimmed, at most 1000 characters (reason_note), and required when a
     * rejection's category is "Other" (decision 2026-10-01). Empty → null.
     *
     * @throws ValidationException
     */
    private function validatedNote(?string $note, ?RejectReason $reason = null): ?string
    {
        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;

        if ($reason === RejectReason::Other && $note === null) {
            throw ValidationException::withMessages(['note' => __('Write a note for the member when the reason is “Other”.')]);
        }

        if ($note !== null && mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => __('Keep the note under 1000 characters.')]);
        }

        return $note;
    }

    /** @param  array<string, mixed>|null  $fields */
    private function close(ModerationItem $item, AdminUser $admin, ModerationStatus $status, ?string $category = null, ?string $note = null, ?array $fields = null): void
    {
        $item->forceFill([
            'status' => $status,
            'decided_by_admin_id' => $admin->id,
            'decided_at' => now(),
            'reason_category' => $category,
            'reason_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'claimed_by_admin_id' => null,
            'claimed_until' => null,
            ...($fields !== null ? ['fields' => $fields] : []),
        ])->save();
    }
}
