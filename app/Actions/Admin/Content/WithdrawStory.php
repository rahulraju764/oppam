<?php

declare(strict_types=1);

namespace App\Actions\Admin\Content;

use App\Domain\Content\StoryWithdrawal;
use App\Enums\ContentStatus;
use App\Exceptions\Admin\ContentRefused;
use App\Models\AdminUser;
use App\Models\SuccessStory;
use App\Services\Audit\AuditLogger;
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
        private readonly StoryWithdrawal $withdrawal,
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
            $this->withdrawal->withdraw($locked, $reason);

            $this->audit->record('content.story_withdrawn', $locked, ['status' => $from->value], ['status' => ContentStatus::Archived->value], $reason, actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }
}
