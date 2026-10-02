<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Moderation;

use App\Actions\Moderation\ApproveProfile;
use App\Actions\Moderation\ClaimModerationItem;
use App\Actions\Moderation\EscalateModerationItem;
use App\Actions\Moderation\RejectProfile;
use App\Data\Moderation\ModerationFlag;
use App\Domain\Media\PhotoUrls;
use App\Domain\Moderation\ModerationFlags;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\RejectReason;
use App\Enums\WizardStep;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Livewire\Member\Profile\ProfileFacts;
use App\Models\AdminUser;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\Profile;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A04 profile review (/moderation/profiles/{code}): the submitted profile, its photos (blurred until
 * revealed, for moderator wellbeing), the automatic pre-flags, and the four decisions — approve,
 * reject (category + note), request changes (wizard step), escalate. Opening it claims the item
 * for config('moderation.claim_minutes'); if someone else holds it the page is read-only.
 * Every decision is an Action (authorized, claimed, audited).
 */
#[Layout('layouts::admin', ['title' => 'Profile review'])]
final class ProfileReview extends Component
{
    #[Locked]
    public string $code = '';

    #[Locked]
    public string $itemId = '';

    public string $reason = '';

    public string $note = '';

    public string $step = '1';

    public ?string $claimedBy = null;

    public function mount(string $profile, ClaimModerationItem $claim): void
    {
        Gate::authorize('moderation.view');

        $item = ModerationItem::query()
            ->ofType(ModerationItemType::ProfileNew)
            ->pending()
            ->whereHas('profile', fn ($q) => $q->where('code', $profile))
            ->latest('submitted_at')
            ->first() ?? abort(404);

        $this->code = $profile;
        $this->itemId = $item->id;
        $admin = $this->admin();

        if ($admin->can('moderation.act') && ($item->status !== ModerationStatus::Escalated || $admin->isSuperAdmin())) {
            try {
                $claim->handle($admin, $item);
            } catch (ModerationItemUnavailable) {
                $this->claimedBy = $item->claimedBy?->name;
            }
        }
    }

    public function approve(ApproveProfile $approve): mixed
    {
        return $this->decide(fn () => $approve->handle($this->admin(), $this->item()), __('Profile :code approved.', ['code' => $this->code]));
    }

    public function reject(RejectProfile $reject): mixed
    {
        $this->validate(['reason' => ['required', 'in:'.implode(',', array_keys(RejectReason::options()))]], [], ['reason' => __('reason')]);

        return $this->decide(fn () => $reject->handle($this->admin(), $this->item(), RejectReason::from($this->reason), $this->note), __('Profile :code rejected.', ['code' => $this->code]));
    }

    public function requestChanges(RejectProfile $reject): mixed
    {
        $this->validate(['step' => ['required', 'integer', 'between:1,6']], [], ['step' => __('step')]);
        $reason = RejectReason::tryFrom($this->reason) ?? RejectReason::Incomplete;

        return $this->decide(fn () => $reject->handle($this->admin(), $this->item(), $reason, $this->note, WizardStep::from((int) $this->step)),
            __('Changes requested for :code.', ['code' => $this->code]));
    }

    public function escalate(EscalateModerationItem $escalate): mixed
    {
        return $this->decide(fn () => $escalate->handle($this->admin(), $this->item(), $this->note), __('Profile :code escalated.', ['code' => $this->code]));
    }

    /** Give the item back to the queue without deciding ("skip"). */
    public function release(ClaimModerationItem $claim): mixed
    {
        $claim->release($this->admin(), $this->item());

        return $this->redirectRoute('admin.moderation.profiles', navigate: true);
    }

    public function render(ModerationFlags $flags, ProfileFacts $facts): View
    {
        $item = $this->item();
        $profile = Profile::query()->whereKey($item->profile_id)
            ->with(['educationCareer', 'familyDetail', 'partnerPreference', 'lifestyleDetail', 'horoscopeDetail', 'user'])
            ->firstOrFail();

        $photos = Media::query()
            ->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)
            ->where('collection_name', Profile::PHOTOS)->orderBy('order_column')->get();

        $texts = [
            __('name') => $profile->fullName(),
            __('about me') => $profile->about,
            __('about family') => $profile->familyDetail?->getAttribute('about_family'),
            __('about partner') => $profile->partnerPreference?->about_partner,
            ...$photos->mapWithKeys(fn (Media $m, int $i): array => [__('caption :n', ['n' => $i + 1]) => $m->caption])->all(),
        ];

        return view('livewire.admin.moderation.profile-review', [
            'item' => $item,
            'profile' => $profile,
            'sections' => $facts->sections($profile, $profile->user),
            'photos' => $photos,
            'flags' => [...$flags->forProfile($profile, $texts), ...$this->storedPhotoFlags($profile)],
            'reasons' => RejectReason::options(),
            'steps' => collect(WizardStep::cases())->mapWithKeys(fn (WizardStep $s): array => [$s->value => $s->value.'. '.$s->title()])->all(),
            'canDecide' => $this->claimedBy === null && $this->admin()->can('moderation.act')
                && ($item->status !== ModerationStatus::Escalated || $this->admin()->isSuperAdmin()),
            'placeholder' => PhotoUrls::PLACEHOLDER,
        ]);
    }

    /**
     * Photo flags were computed once at upload (UploadProfilePhoto) and stored on the PHOTO items.
     * Only photos still waiting count — a decided or deleted photo's flag is history.
     *
     * @return list<ModerationFlag>
     */
    private function storedPhotoFlags(Profile $profile): array
    {
        return ModerationItem::query()->ofType(ModerationItemType::Photo)->where('profile_id', $profile->id)
            ->pending()
            ->whereIn('subject_id', Media::query()->select('uuid')->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)->where('collection_name', Profile::PHOTOS))
            ->pluck('fields')
            ->flatMap(fn (mixed $fields): array => is_array($fields) && is_array($fields['flags'] ?? null) ? $fields['flags'] : [])
            ->unique('code')
            ->map(fn (array $flag): ModerationFlag => new ModerationFlag((string) ($flag['code'] ?? ''), (string) ($flag['message'] ?? '')))
            ->values()
            ->all();
    }

    private function decide(Closure $action, string $done): mixed
    {
        try {
            $action();
        } catch (ModerationItemUnavailable|InvalidArgumentException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        }

        session()->flash('status', $done);

        return $this->redirectRoute('admin.moderation.profiles', navigate: true);
    }

    private function item(): ModerationItem
    {
        // Scoped to the profile in the URL: a tampered id can't reach another item.
        return ModerationItem::query()
            ->whereKey($this->itemId)
            ->ofType(ModerationItemType::ProfileNew)
            ->whereHas('profile', fn ($q) => $q->where('code', $this->code))
            ->first() ?? abort(404);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
