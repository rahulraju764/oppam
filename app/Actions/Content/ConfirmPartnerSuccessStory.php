<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Domain\Content\StoryWithdrawal;
use App\Domain\Profile\MemberHiding;
use App\Domain\Safety\BlockList;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Profile;
use App\Models\SuccessStory;
use App\Models\User;
use App\Notifications\Account\SuccessStoryConfirmedNotification;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The partner named in an M16 story answers it (P8.2c), by story code. The story is looked up only
 * among stories naming the caller as partner and still waiting for them — anything else is a 404.
 *  - confirm: their consent is recorded (A08 may then publish it) and their profile is HIDDEN too
 *    ("both profiles are auto-hidden once confirmed", M16);
 *  - decline: the story is withdrawn and both consents cleared — it can never be published.
 * The submitter is told either way (in-app). Refused in an admin support session.
 */
final class ConfirmPartnerSuccessStory
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Impersonation $impersonation,
        private readonly MemberHiding $hiding,
        private readonly BlockList $blocks,
        private readonly StoryWithdrawal $withdrawal,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException
     */
    public function handle(User $partnerUser, string $storyCode, bool $confirm): SuccessStory
    {
        $this->impersonation->assertAllowed();

        $partner = $partnerUser->isActive() ? Profile::query()->where('user_id', $partnerUser->id)->first() : null;
        if ($partner === null) {
            throw new NotFoundHttpException;
        }

        $story = DB::transaction(function () use ($partnerUser, $partner, $storyCode, $confirm): SuccessStory {
            $story = SuccessStory::query()
                ->where('code', $storyCode)
                ->where('profile_b_id', $partner->id)
                ->whereNull('consent_b_at')
                ->whereNull('withdrawn_at')
                ->lockForUpdate()
                ->first() ?? throw new NotFoundHttpException;

            // Rule 4: a pair blocked since the story was shared never acts on, or hears from, each other.
            $submitter = $story->profile_a_id !== null ? Profile::query()->find($story->profile_a_id) : null;
            if ($submitter === null || $this->blocks->isBlocked($partner, $submitter)) {
                throw new NotFoundHttpException;
            }

            if ($confirm) {
                $story->forceFill(['consent_b_at' => now()])->save();
                $locked = Profile::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
                $hidden = $this->hiding->hide($locked);

                $this->audit->record('content.story_confirmed_by_partner', $story, after: ['partner_hidden' => $hidden],
                    actor: $partnerUser, subjectLabel: $story->code);
            } else {
                $this->withdrawal->withdraw($story, __('Declined by the partner.'));

                $this->audit->record('content.story_declined_by_partner', $story, actor: $partnerUser, subjectLabel: $story->code);
            }

            return $story;
        });

        $submitter = $story->profile_a_id !== null ? Profile::query()->with('user')->find($story->profile_a_id) : null;
        $submitter?->user?->notify(new SuccessStoryConfirmedNotification($partner->first_name, $confirm));

        return $story;
    }
}
