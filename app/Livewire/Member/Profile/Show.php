<?php

declare(strict_types=1);

namespace App\Livewire\Member\Profile;

use App\Actions\Profile\RecordProfileView;
use App\Actions\Profile\ViewContact;
use App\Data\Content\SeoData;
use App\Domain\Matching\PreferenceMatcher;
use App\Domain\Media\HoroscopeAccess;
use App\Domain\Media\PhotoUrls;
use App\Domain\Profile\ContactAccessPolicy;
use App\Domain\Profile\ProfileCards;
use App\Domain\Profile\ProfileNames;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\UserRole;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\ContactNotAvailable;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Profile\SimilarProfilesQuery;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\ProfileBrowseList;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /profile/{code} — a member's profile (M03, template single-profile.php). Only members who may
 * see it get anything (ProfileVisibility: ACTIVE, opposite gender, not blocked — else 404,
 * R-M03-1/3); the owner always may, and ?preview=1 shows them what others see. Photos through
 * PhotoUrls, contact details only through ViewContact (R-M03-2), name masked (ProfileNames).
 * The like / favourite / interest / chat actions arrive in P3.x.
 */
final class Show extends Component
{
    #[Locked]
    public string $code = '';

    #[Locked]
    public bool $preview = false;

    /** @var array<string, string|null>|null revealed contact details (after ViewContact) */
    public ?array $contact = null;

    public ?string $contactError = null;

    public function mount(string $profile, ProfileVisibility $visibility, RecordProfileView $record): void
    {
        $viewer = $this->viewer();
        $target = Profile::query()->where('code', $profile)->first();

        if ($target === null || ! $visibility->canView($target, $viewer)) {
            abort(404);
        }

        $this->code = $target->code;
        $this->preview = $visibility->isOwner($target, $viewer) && request()->boolean('preview');

        $record->handle($viewer, $target);
    }

    /** "View contact" after the member confirmed: uses one contact view the first time (R-M03-2). */
    public function viewContact(ViewContact $view): void
    {
        $this->contactError = null;

        try {
            $card = $view->handle($this->viewer(), $this->target());
            $this->contact = (array) $card;
        } catch (ContactNotAvailable|ImpersonationRestricted $e) {
            $this->contactError = $e->getMessage();
        }

        $this->dispatch('close-modal', name: 'view-contact');
    }

    public function render(
        PhotoUrls $photos,
        ProfileFacts $facts,
        ProfileNames $names,
        PreferenceMatcher $matcher,
        ContactAccessPolicy $contactPolicy,
        SimilarProfilesQuery $similar,
        ProfileCards $cards,
        HoroscopeAccess $horoscope,
        ProfileBrowseList $browse,
        Navigation $nav,
    ): View {
        $viewer = $this->viewer();
        $target = $this->target();
        $own = $viewer->profile;
        $isOwner = $target->user_id === $viewer->id;
        $asOthers = ! $isOwner || $this->preview;

        return view('livewire.member.profile.show', [
            'profile' => $target,
            'isOwner' => $isOwner,
            'asOthers' => $asOthers,
            'name' => $asOthers ? $names->forViewer($target, null) : $target->fullName(),
            'photos' => $this->preview ? $photos->forPreview($target) : $photos->forViewer($target, $viewer),
            'headline' => $facts->headline($target),
            'sections' => $facts->sections($target, $asOthers ? null : $viewer),
            'checks' => ! $isOwner && $own !== null ? $matcher->check($own, $target->partnerPreference) : [],
            'contactAccess' => ! $isOwner && $own !== null ? $contactPolicy->decide($target, $own) : null,
            'horoscopeLink' => $horoscope->link($target, $viewer),
            'similar' => ! $isOwner && $own !== null
                ? $similar->for($target, $own)->map(fn (Profile $p) => $cards->forViewer($p, $viewer))->all()
                : [],
            'neighbours' => $browse->neighbours($target->code),
            'plansUrl' => $nav->url('plans'),
        ])->layout('layouts::member', ['seo' => SeoData::private($names->forViewer($target, null).' | Oppam Matrimony')]);
    }

    private function target(): Profile
    {
        $target = Profile::query()
            ->where('code', $this->code)
            ->with(['educationCareer', 'familyDetail', 'partnerPreference', 'contactDetail', 'lifestyleDetail', 'horoscopeDetail', 'privacySetting', 'user'])
            ->first();

        // Re-checked on every request: a block or a status change since mount takes effect at once.
        if ($target === null || ! app(ProfileVisibility::class)->canView($target, $this->viewer())) {
            abort(404);
        }

        return $target;
    }

    private function viewer(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User || $user->role !== UserRole::Member) {
            abort(404);
        }

        return $user;
    }
}
