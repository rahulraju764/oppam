<?php

declare(strict_types=1);

namespace App\Queries\Moderation;

use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Models\ModerationItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * The A04 queue order (PRD §11 A04): waiting items of one type, paid-member lane first, then
 * oldest first. Escalated items have their own list (super admins).
 */
final class ModerationQueue
{
    /** @return Builder<ModerationItem> */
    public function open(ModerationItemType $type, bool $priorityOnly = false): Builder
    {
        return ModerationItem::query()
            ->ofType($type)
            ->where('status', ModerationStatus::Open)
            ->when($priorityOnly, fn (Builder $q) => $q->where('is_priority', true))
            ->orderByDesc('is_priority')
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    /** @return Builder<ModerationItem> */
    public function escalated(): Builder
    {
        return ModerationItem::query()
            ->where('status', ModerationStatus::Escalated)
            ->orderByDesc('is_priority')
            ->orderBy('submitted_at')
            ->orderBy('id');
    }
}
