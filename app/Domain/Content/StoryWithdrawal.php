<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Enums\ContentStatus;
use App\Models\SuccessStory;
use App\Queries\Content\HomeCounters;
use App\Services\Content\ContentImageStore;
use Illuminate\Support\Facades\DB;

/**
 * Taking a success story down once consent is gone (A08 / M16): archived, both consents and any
 * waiting edit cleared, the couple's photo deleted (after commit — a file can't be rolled back)
 * and the home counters refreshed. One place for the member paths (withdraw, partner declines)
 * so they do exactly what the admin withdrawal does. The caller holds the row lock and audits.
 */
final class StoryWithdrawal
{
    public function __construct(
        private readonly ContentImageStore $images,
        private readonly HomeCounters $counters,
    ) {}

    public function withdraw(SuccessStory $story, string $reason): void
    {
        $photos = [$story->image_path, $story->draft['image_path'] ?? null];

        $story->forceFill([
            'status' => ContentStatus::Archived, 'archived_at' => now(), 'scheduled_at' => null, 'draft' => null,
            'withdrawn_at' => now(), 'withdrawn_reason' => $reason,
            'consent_a_at' => null, 'consent_b_at' => null, 'consent_note' => null,
            'image_path' => null, 'image_width' => null, 'image_height' => null,
        ])->save();

        DB::afterCommit(function () use ($photos): void {
            $this->counters->forget();
            foreach ($photos as $photo) {
                $this->images->delete(is_string($photo) ? $photo : null);
            }
        });
    }
}
