<?php

declare(strict_types=1);

namespace App\Livewire\Member\Engagement;

use App\Actions\Engagement\RemoveFavorites;
use App\Actions\Engagement\RemoveUnavailableFavorites;
use App\Actions\Engagement\SaveFavoriteNote;
use App\Actions\Engagement\SendInterests;
use App\Data\Content\SeoData;
use App\Domain\Engagement\FavoritePrivacy;
use App\Domain\Engagement\InterestMessages;
use App\Domain\Profile\ProfileCards;
use App\Enums\Entitlement;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Engagement\EngagementLists;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /favorites (PRD M06): the member's private bookmarks, newest first, each with its private note;
 * remove one with the bookmark on the photo, or tick several and remove them together. Read
 * through EngagementLists (blocked / hidden members never listed); every write goes through an
 * Action scoped to the member's own favourites. Bookmarks whose profile is no longer available
 * still hold a slot of the plan, so the page offers to clear them. Nobody else ever sees this
 * list (R-M06-6) — in an admin support session only the number is shown (FavoritePrivacy).
 * "Send interest to all selected" goes through SendInterests (quota respected).
 */
final class Favorites extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    /** @var list<string> profile codes ticked for "remove selected" (re-checked by the Action) */
    public array $selected = [];

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'favorite-changed' => 'favoriteChanged',   // a bookmark removed with the button on a row
            // A block either way removed favourites (R-M06-4): the list re-reads.
            'echo-private:App.Models.User.'.$this->member()->id.',.connections.changed' => '$refresh',
        ];
    }

    /** A row's own star changed: it can't stay ticked for "remove selected". */
    public function favoriteChanged(string $code = ''): void
    {
        $this->selected = array_values(array_filter($this->selected, fn (mixed $ticked): bool => $ticked !== $code));
    }

    public function saveNote(string $code, string $note, SaveFavoriteNote $save): void
    {
        $this->resetErrorBag('note.'.$code);

        try {
            $saved = $save->handle($this->member(), $code, $note);
        } catch (ValidationException $invalid) {
            $this->addError('note.'.$code, (string) collect($invalid->errors())->flatten()->first());

            return;
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return;
        }

        if ($saved) {
            $this->dispatch('toast', title: __('Note saved.'), type: 'success');
        }
    }

    public function removeSelected(RemoveFavorites $remove): void
    {
        $this->removed(fn (): int => $remove->handle($this->member(), $this->selected));
        $this->selected = [];
    }

    /** "Send interest to all selected" (M06, respecting the month's quota), with the first ready message. */
    public function interestSelected(SendInterests $send, InterestMessages $messages): void
    {
        try {
            $result = $send->handle($this->member(), $this->selected, $messages->templates()[0]->code ?? null);
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return;
        }

        $this->selected = [];

        if ($result['quotaReached']) {
            $this->dispatch('quota-reached', title: __('You’ve used this month’s interests'),
                message: trans_choice(':count interest was sent before your plan’s monthly limit was reached.|:count interests were sent before your plan’s monthly limit was reached.', $result['sent'], ['count' => $result['sent']]));

            return;
        }

        $this->dispatch('toast', type: $result['sent'] > 0 ? 'success' : 'info', title: trans_choice(':count interest sent.|:count interests sent.', $result['sent'], ['count' => $result['sent']])
            .($result['skipped'] > 0 ? ' '.trans_choice(':count profile skipped (already contacted or not accepting interests).|:count profiles skipped (already contacted or not accepting interests).', $result['skipped'], ['count' => $result['skipped']]) : ''));
    }

    /** Clear bookmarks of profiles that are no longer available (they still hold plan slots). */
    public function removeUnavailable(RemoveUnavailableFavorites $remove): void
    {
        $this->removed(fn (): int => $remove->handle($this->member()));
    }

    public function render(EngagementLists $lists, ProfileCards $cards, EntitlementService $entitlements, FavoritePrivacy $privacy): View
    {
        $member = $this->member();
        $me = $member->profile ?? abort(404);
        $hidden = $privacy->hiddenInThisSession();

        $rows = [];
        $page = null;

        if (! $hidden) {
            $page = $this->page($lists, $me);
            $profiles = collect($page->items());
            $cardData = $cards->forViewers($profiles, $member);

            foreach ($profiles->values() as $i => $profile) {
                /** @var Profile $profile */
                $rows[] = ['card' => $cardData[$i], 'note' => (string) ($profile->getAttribute('favorite_note') ?? '')];
            }
        }

        return view('livewire.member.engagement.favorites', [
            'hidden' => $hidden,
            'rows' => $rows,
            'page' => $page,
            'limit' => $entitlements->limit($me, Entitlement::FavoritesTotal),
            'used' => $entitlements->used($me, Entitlement::FavoritesTotal),
            'unavailable' => $hidden ? 0 : $lists->unavailableFavoritesCount($me),
            'noteMax' => SaveFavoriteNote::MAX,
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Favorites | Oppam Matrimony'),
        ]);
    }

    /**
     * The current page — or the first one when the page the member was on has just been emptied.
     *
     * @return LengthAwarePaginator<int, Profile>
     */
    private function page(EngagementLists $lists, Profile $me): LengthAwarePaginator
    {
        $page = $lists->favorites($me)->with('privacySetting')->paginate(self::PER_PAGE);

        if ($page->isEmpty() && $page->currentPage() > 1) {
            $this->resetPage();
            $page = $lists->favorites($me)->with('privacySetting')->paginate(self::PER_PAGE);
        }

        return $page;
    }

    /** @param  callable(): int  $remove */
    private function removed(callable $remove): void
    {
        try {
            $count = $remove();
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return;
        }

        if ($count > 0) {
            $this->dispatch('toast', title: trans_choice(':count favourite removed.|:count favourites removed.', $count, ['count' => $count]), type: 'success');
        }
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
