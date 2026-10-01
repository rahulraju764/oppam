<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhotoStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * A stored file (spatie/laravel-medialibrary) with Oppam's photo columns (M11): moderation_status
 * (only APPROVED photos are shown to others), rejection_reason, caption and the perceptual hash.
 * The status is set by moderation Actions only (A04, P1.6) — never mass-assigned.
 *
 * @property PhotoStatus $moderation_status
 * @property string|null $rejection_reason
 * @property string|null $caption
 * @property string|null $phash
 */
final class Media extends BaseMedia
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'moderation_status' => PhotoStatus::class,
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('moderation_status', PhotoStatus::Approved);
    }

    /**
     * Pending + approved: what counts towards the 10-photo limit and "has a photo".
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotRejected(Builder $query): void
    {
        $query->where('moderation_status', '!=', PhotoStatus::Rejected);
    }
}
