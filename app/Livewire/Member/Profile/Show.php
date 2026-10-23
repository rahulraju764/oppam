<?php

declare(strict_types=1);

namespace App\Livewire\Member\Profile;

use App\Actions\Engagement\RequestAccess;
use App\Actions\Profile\RecordProfileView;
use App\Actions\Profile\ViewContact;
use App\Data\Content\SeoData;
use App\Domain\Engagement\Connections;
use App\Domain\Matching\PreferenceMatcher;
use App\Domain\Media\HoroscopeAccess;
use App\Domain\Media\PhotoUrls;
use App\Domain\Profile\ContactAccessPolicy;
use App\Domain\Profile\ProfileCards;
use App\Domain\Profile\ProfileNames;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\AccessRequestType;
use App\Enums\UserRole;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
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
 * Like / Favorite (P3.3), Send Interest → Chat and photo / horoscope requests (P3.4) are live;
 * Block / Ignore / Report (SafetyMenu, P6.2) — a block either way takes the page away live.
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

    /** What happened to the last photo / horoscope request made from this page. */
    public ?string $requestNotice = null;

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

    /**
     * Live (P6.2): a block or unblock between this member and someone (`.connections.changed`, no
     * payload) — if this profile is no longer visible, the page leaves for the dashboard.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ['echo-private:App.Models.User.'.$this->viewer()->id.',.connections.changed' => 'recheck'];
    }

    public function recheck(ProfileVisibility $visibility): void
    {
        $target = Profile::query()->where('code', $this->code)->first();
        if ($target === null || ! $visibility->canView($target, $this->viewer())) {
            $this->redirectRoute('member.dashboard', navigate: true);
        }
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

    /** Ask to see photos / the horoscope the member shares on request (M06). */
    public function requestAccess(string $type, RequestAccess $request): void
    {
        $kind = AccessRequestType::tryFrom($type);

        if ($kind === null) {
            return;
        }

        try {
            $request->handle($this->viewer(), $this->target(), $kind);
            $this->requestNotice = __('Request sent. You will be told when it is approved.');
        } catch (EngagementRefused|ImpersonationRestricted $refused) {
            $this->requestNotice = $refused->getMessage();
        }
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
        Connections $connections,
        RequestAccess $requests,
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
            // Others see the masked name — unless connected by an accepted interest (ProfileNames);
            // the owner's preview shows what a stranger sees.
            'name' => $isOwner ? ($this->preview ? $names->forViewer($target, null) : $target->fullName()) : $names->forViewer($target, $viewer),
            'interestState' => ! $isOwner && $own !== null ? $connections->stateWith($own, $target) : Connections::NONE,
            'requestable' => ! $isOwner && $own !== null ? $this->requestable($requests, $target, $viewer, $own) : [],
            'photos' => $this->preview ? $photos->forPreview($target) : $photos->forViewer($target, $viewer),
            'headline' => $facts->headline($target),
            'sections' => $facts->sections($target, $this->preview ? null : $viewer),   // the Name row follows the same rule as the heading
            'checks' => ! $isOwner && $own !== null ? $matcher->check($own, $target->partnerPreference) : [],
            'contactAccess' => ! $isOwner && $own !== null ? $contactPolicy->decide($target, $own) : null,
            'horoscopeLink' => $horoscope->link($target, $viewer),
            'similar' => ! $isOwner && $own !== null
                ? $cards->forViewers($similar->for($target, $own), $viewer, withEngagement: false)   // one photo + one block query; member-cards have no buttons
                : [],
            'neighbours' => $browse->neighbours($target->code),
            'plansUrl' => $nav->url('plans'),
        ])->layout('layouts::member', ['seo' => SeoData::private($names->forViewer($target, null).' | Oppam Matrimony')]);
    }

    /**
     * Content the member shares on request that this viewer can't open yet: type => already asked.
     *
     * @return array<string, bool>
     */
    private function requestable(RequestAccess $requests, Profile $target, User $viewer, Profile $own): array
    {
        $out = [];

        foreach (AccessRequestType::cases() as $type) {
            if ($requests->requestable($target, $viewer, $type)) {
                $out[$type->value] = $requests->isPending($own, $target, $type);
            }
        }

        return $out;
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
