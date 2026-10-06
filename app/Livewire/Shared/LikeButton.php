<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Actions\Engagement\ToggleLike;
use App\Domain\Profile\ProfileCards;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Like;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Like ♥ button (PRD M06 `Shared\LikeButton`) on the profile page, list rows and slider tiles.
 * Alpine shows the click at once (optimistic); like() answers with the state ToggleLike really
 * left, so a refusal — daily limit, spam block, profile gone — rolls the button back and says why
 * (upgrade dialog or toast). A like that completes a pair opens "It's a match!".
 * The profile is held by CODE, locked; every rule is ToggleLike's.
 */
final class LikeButton extends Component
{
    public const VARIANTS = ['row', 'page', 'tile'];

    #[Locked]
    public string $profileCode = '';

    #[Locked]
    public string $variant = 'row';

    #[Locked]
    public bool $liked = false;

    /** Shown in the button's accessible name ("Like Anna T."). Display text only. */
    #[Locked]
    public string $name = '';

    /** $liked comes from the page's batch lookup (ProfileCards); null = look it up here. */
    public function mount(string $profileCode, ?bool $liked = null, string $variant = 'row', string $name = ''): void
    {
        $this->profileCode = $profileCode;
        $this->name = $name;
        $this->variant = in_array($variant, self::VARIANTS, true) ? $variant : 'row';
        $this->liked = $liked ?? $this->currentlyLiked();
    }

    /** @return bool the state after the call — what the button must show */
    public function like(bool $on, ToggleLike $toggle, ProfileCards $cards): bool
    {
        $member = $this->member();
        $target = Profile::withTrashed()->where('code', $this->profileCode)->first();

        try {
            $result = $toggle->handle($member, $target ?? throw new NotFoundHttpException, $on);
        } catch (QuotaExceeded $quota) {
            $this->dispatch('quota-reached',
                title: __('You’ve used today’s likes'),
                message: trans_choice('Your plan includes :count like a day. Upgrade for more, or come back tomorrow.|Your plan includes :count likes a day. Upgrade for more, or come back tomorrow.', $quota->limit, ['count' => $quota->limit]));

            return $this->liked;
        } catch (EngagementRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', title: $refused->getMessage(), type: 'danger');

            return $this->liked;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', title: __('This profile is no longer available.'), type: 'danger');

            return $this->liked = false;
        }

        $this->liked = $result->liked;
        $this->dispatch('like-changed', code: $this->profileCode, on: $this->liked);

        if ($result->changed && $result->mutual) {
            $card = $cards->forViewer($target, $member);
            $this->dispatch('its-a-match', name: $card->name, url: (string) $card->url, photo: $card->photoUrl);
        }

        return $this->liked;
    }

    public function render(): View
    {
        return view('livewire.shared.like-button');
    }

    private function currentlyLiked(): bool
    {
        $own = $this->member()->profile;

        return $own !== null && Like::query()
            ->where('from_profile_id', $own->id)
            ->whereIn('to_profile_id', Profile::query()->select('id')->where('code', $this->profileCode))
            ->exists();
    }

    private function member(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(404);
    }
}
