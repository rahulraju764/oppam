<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Data\Content\MemberStoryData;
use App\Domain\Engagement\Connections;
use App\Domain\Profile\MemberHiding;
use App\Domain\Safety\BlockList;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\ProfileStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\Profile;
use App\Models\SuccessStory;
use App\Models\User;
use App\Notifications\Account\SuccessStoryConfirmationRequest;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ContentImageStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * "I found my match on Oppam" (PRD M16, P8.2c). The member names their partner by profile code and
 * shares the wedding date, an optional photo and their story:
 *  - the partner must be a member they are connected with (an accepted interest — owner to confirm),
 *    of the opposite gender, live or hidden, and not blocked either way; one open story per couple;
 *  - the story arrives in A08 as IN_REVIEW with the submitter's consent; it can't be published
 *    before the partner consents too (SuccessStory::publishBlocker);
 *  - the submitter's profile is HIDDEN, not deleted (owner 2026-11-07 (3)); the partner's is hidden
 *    when they confirm (ConfirmPartnerSuccessStory);
 *  - the partner is asked in-app (and by email if verified) — never told anything private;
 *  - 3 submissions a day; refused in an admin support session.
 * Every "no" about the partner is the same message, so the form can't be used to probe codes.
 */
final class SubmitMemberSuccessStory
{
    public const PER_DAY = 3;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContentImageStore $images,
        private readonly Impersonation $impersonation,
        private readonly BlockList $blocks,
        private readonly Connections $connections,
        private readonly MemberHiding $hiding,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws SettingsRefused
     * @throws ValidationException
     */
    public function handle(User $user, MemberStoryData $data): SuccessStory
    {
        $this->impersonation->assertAllowed();

        $mine = $user->isActive() ? Profile::query()->where('user_id', $user->id)->first() : null;
        if ($mine === null || ! in_array($mine->status, [ProfileStatus::Active, ProfileStatus::Hidden], true)) {
            throw new NotFoundHttpException;
        }

        $partner = $this->partner($mine, $data->partnerCode);

        $key = 'member-story:'.$mine->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_DAY)) {
            throw SettingsRefused::storyTooSoon();
        }

        $image = $data->photo !== null ? $this->images->store($data->photo) : null;

        try {
            $story = DB::transaction(function () use ($user, $mine, $partner, $data, $image): SuccessStory {
                $mine = Profile::query()->whereKey($mine->id)->lockForUpdate()->firstOrFail();

                if ($this->openStoryExists($mine, $partner)) {
                    throw ValidationException::withMessages(['partnerCode' => __('A story for the two of you has already been shared.')]);
                }

                $story = new SuccessStory;
                $story->forceFill([
                    'code' => $this->newCode(),
                    'status' => ContentStatus::InReview,
                    'slug' => Str::slug($mine->first_name.' '.$partner->first_name).'-'.Str::lower(Str::random(6)),
                    'couple' => Str::limit($mine->first_name.' & '.$partner->first_name, 120, ''),
                    'place' => Str::limit(trim((string) $data->place) !== '' ? trim((string) $data->place) : 'Kerala', 80, ''),
                    'wedding_date' => $data->weddingDate,
                    'quote' => Str::limit(trim($data->quote), 300, ''),
                    'body' => trim($data->body),
                    'image_path' => $image['image_path'] ?? null,
                    'image_width' => $image['image_width'] ?? null,
                    'image_height' => $image['image_height'] ?? null,
                    'profile_a_id' => $mine->id,
                    'profile_b_id' => $partner->id,
                    'consent_a_at' => now(),
                ])->save();

                $hidden = $this->hiding->hide($mine);

                $this->audit->record('content.story_submitted_by_member', $story,
                    after: ['code' => $story->code, 'partner' => $partner->code, 'submitter_hidden' => $hidden],
                    actor: $user, subjectLabel: $story->code);

                return $story;
            });
        } catch (Throwable $e) {
            $this->images->delete($image['image_path'] ?? null);

            throw $e;
        }

        RateLimiter::hit($key, 86400);
        $partner->user?->notify(new SuccessStoryConfirmationRequest($story->code, $mine->first_name));

        return $story;
    }

    /** @throws ValidationException one message for every reason — the form doesn't reveal codes */
    private function partner(Profile $mine, string $code): Profile
    {
        $partner = Profile::query()->where('code', mb_strtoupper(trim($code)))->first();

        $ok = $partner !== null
            && $partner->id !== $mine->id
            && $partner->user_id !== null
            && $partner->gender !== $mine->gender
            && in_array($partner->status, [ProfileStatus::Active, ProfileStatus::Hidden], true)
            && ! $this->blocks->isBlocked($mine, $partner)
            && $this->connections->isConnected($mine, $partner);

        if (! $ok) {
            throw ValidationException::withMessages(['partnerCode' => __('We can’t add a story with this profile code. Check the code — you can name a member you connected with on Oppam (an accepted interest).')]);
        }

        return $partner;
    }

    private function openStoryExists(Profile $a, Profile $b): bool
    {
        return SuccessStory::query()
            ->whereNull('withdrawn_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('profile_a_id', $a->id)->where('profile_b_id', $b->id))
                ->orWhere(fn ($q) => $q->where('profile_a_id', $b->id)->where('profile_b_id', $a->id)))
            ->exists();
    }

    private function newCode(): string
    {
        do {
            $code = ContentType::Story->codePrefix().Str::upper(Str::random(8));
        } while (SuccessStory::query()->where('code', $code)->exists());

        return $code;
    }
}
