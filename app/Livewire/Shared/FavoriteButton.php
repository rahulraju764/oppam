<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Actions\Engagement\ToggleFavorite;
use App\Domain\Engagement\FavoritePrivacy;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Favorite;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Favorite ☆ bookmark (PRD M06 `Shared\FavoriteButton`): the template's "Shortlist" control on
 * a row's photo, and a button on the profile page. Private — nobody but the member ever learns of
 * it (R-M06-6). Optimistic in Alpine; favorite() answers with the state ToggleFavorite really left
 * (plan's total reached → upgrade dialog; anything else refused → toast).
 */
final class FavoriteButton extends Component
{
    public const VARIANTS = ['photo', 'page'];

    #[Locked]
    public string $profileCode = '';

    #[Locked]
    public string $variant = 'photo';

    #[Locked]
    public bool $favorited = false;

    /** Shown in the button's accessible name ("Add Anna T. to favourites"). Display text only. */
    #[Locked]
    public string $name = '';

    public function mount(string $profileCode, ?bool $favorited = null, string $variant = 'photo', string $name = ''): void
    {
        $this->profileCode = $profileCode;
        $this->variant = in_array($variant, self::VARIANTS, true) ? $variant : 'photo';
        $this->name = $name;
        $this->favorited = $favorited ?? $this->currentlyFavorited();
    }

    /** @return bool the state after the call — what the button must show */
    public function favorite(bool $on, ToggleFavorite $toggle): bool
    {
        $target = Profile::withTrashed()->where('code', $this->profileCode)->first();

        try {
            $this->favorited = $toggle->handle($this->member(), $target ?? throw new NotFoundHttpException, $on);
        } catch (QuotaExceeded $quota) {
            $this->dispatch('quota-reached',
                title: __('Your favourites are full'),
                message: trans_choice('Your plan keeps up to :count favourite. Remove one, or upgrade for more.|Your plan keeps up to :count favourites. Remove some, or upgrade for more.', $quota->limit, ['count' => $quota->limit]));

            return $this->favorited;
        } catch (EngagementRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', title: $refused->getMessage(), type: 'danger');

            return $this->favorited;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', title: __('This profile is no longer available.'), type: 'danger');

            return $this->favorited = false;
        }

        $this->dispatch('favorite-changed', code: $this->profileCode, on: $this->favorited);

        return $this->favorited;
    }

    public function render(): View
    {
        return view('livewire.shared.favorite-button');
    }

    private function currentlyFavorited(): bool
    {
        $own = $this->member()->profile;

        // R-M06-6: an admin support session never learns whom the member bookmarked.
        if (app(FavoritePrivacy::class)->hiddenInThisSession()) {
            return false;
        }

        return $own !== null && Favorite::query()
            ->where('profile_id', $own->id)
            ->whereIn('favorite_profile_id', Profile::query()->select('id')->where('code', $this->profileCode))
            ->exists();
    }

    private function member(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(404);
    }
}
