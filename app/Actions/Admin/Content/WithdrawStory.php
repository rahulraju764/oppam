<?php

declare(strict_types=1);

namespace App\Actions\Admin\Content;

use App\Enums\ContentStatus;
use App\Exceptions\Admin\ContentRefused;
use App\Models\AdminUser;
use App\Models\SuccessStory;
use App\Queries\Content\HomeCounters;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ContentImageStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A08 consent withdrawal (`content.publish` + typed reason). The story leaves the site at once
 * from any status, its photos are deleted from the public disk, and the recorded consent is
 * cleared, so it can't return without fresh consent and a new photo.
 */
final class WithdrawStory
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContentImageStore $images,
        private readonly HomeCounters $counters,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws ContentRefused
     */
    public function handle(AdminUser $admin, SuccessStory $story, string $reason): SuccessStory
    {
        Gate::forUser($admin)->authorize('content.publish');
        $reason = ReasonRule::check($reason);

        return DB::transaction(function () use ($admin, $story, $reason): SuccessStory {
            /** @var SuccessStory $locked */
            $locked = SuccessStory::query()->whereKey($story->id)->lockForUpdate()->firstOrFail();
            if ($locked->isWithdrawn()) {
                throw ContentRefused::alreadyWithdrawn();
            }

            $from = $locked->status;
            $photos = [$locked->image_path, $locked->draft['image_path'] ?? null];

            $locked->forceFill([
                'status' => ContentStatus::Archived, 'archived_at' => now(), 'scheduled_at' => null, 'draft' => null,
                'withdrawn_at' => now(), 'withdrawn_reason' => $reason,
                'consent_a_at' => null, 'consent_b_at' => null, 'consent_note' => null,
                'image_path' => null, 'image_width' => null, 'image_height' => null,
            ])->save();

            $this->audit->record('content.story_withdrawn', $locked, ['status' => $from->value], ['status' => ContentStatus::Archived->value], $reason, actor: $admin, subjectLabel: $locked->code);

            DB::afterCommit(function () use ($photos): void {
                $this->counters->forget();
                foreach ($photos as $photo) {
                    $this->images->delete(is_string($photo) ? $photo : null);
                }
            });

            return $locked;
        });
    }
}
