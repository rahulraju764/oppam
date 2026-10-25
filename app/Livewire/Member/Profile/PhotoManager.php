<?php

declare(strict_types=1);

namespace App\Livewire\Member\Profile;

use App\Domain\Media\HoroscopeAccess;
use App\Domain\Media\PhotoUrls;
use App\Enums\UserRole;
use App\Livewire\Concerns\ManagesProfilePhotos;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * <livewire:member.profile.photo-manager /> — the member's own photos and horoscope (M11), used by
 * wizard step 6 and (P1.5) My Profile. Upload with progress after an in-browser crop, drag or
 * buttons to reorder, make primary, caption, delete. Every change goes through an Action
 * (ManagesProfilePhotos); the profile is always the signed-in member's own. Tells the page
 * "photos-changed" afterwards.
 */
final class PhotoManager extends Component
{
    use ManagesProfilePhotos, WithFileUploads;

    public function render(PhotoUrls $urls, HoroscopeAccess $horoscope): View
    {
        $profile = $this->photoProfile();
        $user = $this->photoActor();

        return view('livewire.member.profile.photo-manager', [
            'photos' => $urls->forViewer($profile, $user),
            'maxPhotos' => (int) config('oppam.media.max_photos'),
            'hasHoroscope' => $profile->hasMedia(Profile::HOROSCOPE),
            'horoscopeLink' => $horoscope->link($profile, $user),
        ]);
    }

    protected function photoProfile(): Profile
    {
        return $this->photoActor()->profile ?? abort(404);
    }

    protected function photoActor(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User || $user->role !== UserRole::Member) {
            abort(404);
        }

        return $user;
    }
}
