<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\DecidesModerationItems;
use App\Enums\ModerationStatus;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\ModerationItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Open an item for review (A04 "15-min soft claim"): the moderator holds it for
 * config('moderation.claim_minutes'); others see "being reviewed by …" and can't decide it.
 * Release gives it back early (e.g. "skip").
 */
final class ClaimModerationItem
{
    use DecidesModerationItems;

    /**
     * @throws AuthorizationException
     * @throws ModerationItemUnavailable
     */
    public function handle(AdminUser $admin, ModerationItem $item): void
    {
        $this->authorizeDecision($admin, $item);
        $this->holdClaim($admin, $item);
    }

    /**
     * Claim a batch (the A04 photo grid) with ONE conditional UPDATE, then read back which items
     * are now ours. Only OPEN items — escalated ones stay with super admins.
     *
     * @param  list<string>  $ids
     * @return list<string> the ids this moderator now holds
     *
     * @throws AuthorizationException
     */
    public function many(AdminUser $admin, array $ids): array
    {
        Gate::forUser($admin)->authorize('moderation.act');

        if ($ids === []) {
            return [];
        }

        ModerationItem::query()
            ->whereKey($ids)
            ->where('status', ModerationStatus::Open)
            ->where(fn ($q) => $q->whereNull('claimed_by_admin_id')
                ->orWhere('claimed_until', '<', now())
                ->orWhere('claimed_by_admin_id', $admin->id))
            ->update([
                'claimed_by_admin_id' => $admin->id,
                'claimed_until' => now()->addMinutes((int) config('moderation.claim_minutes')),
                'updated_at' => now(),
            ]);

        return ModerationItem::query()->whereKey($ids)->where('claimed_by_admin_id', $admin->id)
            ->where('status', ModerationStatus::Open)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    public function release(AdminUser $admin, ModerationItem $item): void
    {
        ModerationItem::query()
            ->whereKey($item->id)
            ->where('claimed_by_admin_id', $admin->id)
            ->update(['claimed_by_admin_id' => null, 'claimed_until' => null, 'updated_at' => now()]);
    }
}
