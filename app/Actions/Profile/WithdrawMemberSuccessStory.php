<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Profile;
use App\Models\SuccessStory;
use App\Models\User;
use App\Domain\Content\StoryWithdrawal;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Either partner withdraws an M16 story they are part of (P8.2c; A08 "withdrawal" — consent can
 * be taken back at any time). By story code, looked up only among stories naming the caller; a
 * withdrawn story loses both consents, so it can't be published (or leaves the site at once).
 * The profiles stay hidden — the member unhides theirs from the same tab if they wish.
 */
final class WithdrawMemberSuccessStory
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Impersonation $impersonation,
        private readonly StoryWithdrawal $withdrawal,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException
     */
    public function handle(User $user, string $storyCode): SuccessStory
    {
        $this->impersonation->assertAllowed();

        $profile = $user->isActive() ? Profile::query()->where('user_id', $user->id)->first() : null;
        if ($profile === null) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($user, $profile, $storyCode): SuccessStory {
            $story = SuccessStory::query()
                ->where('code', $storyCode)
                ->whereNull('withdrawn_at')
                ->where(fn ($q) => $q->where('profile_a_id', $profile->id)->orWhere('profile_b_id', $profile->id))
                ->lockForUpdate()
                ->first() ?? throw new NotFoundHttpException;

            $from = $story->status;
            $this->withdrawal->withdraw($story, __('Withdrawn by a partner.'));

            $this->audit->record('content.story_withdrawn_by_member', $story, ['status' => $from->value],
                ['status' => $story->status->value], actor: $user, subjectLabel: $story->code);

            return $story;
        });
    }
}
