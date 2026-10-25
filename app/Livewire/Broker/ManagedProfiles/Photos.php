<?php

declare(strict_types=1);

namespace App\Livewire\Broker\ManagedProfiles;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Media\PhotoUrls;
use App\Enums\BureauPermission;
use App\Livewire\Concerns\ManagesProfilePhotos;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * <livewire:broker.managed-profiles.photos :profile-code="…" /> — a managed client's photos and
 * horoscope in the bureau form (§11A B.6 "Photos & horoscope"), the member photo manager's view
 * and Actions (ManagesProfilePhotos). The profile is found inside the caller's ManagedProfileScope
 * on every request (`profiles.edit`); the code is locked. New photos go through A04 like any.
 */
final class Photos extends Component
{
    use ManagesProfilePhotos, WithFileUploads;

    #[Locked]
    public string $profileCode = '';

    public function mount(string $profileCode): void
    {
        $this->profileCode = $profileCode;
        $this->photoProfile();
    }

    public function render(PhotoUrls $urls): View
    {
        $profile = $this->photoProfile();

        return view('livewire.member.profile.photo-manager', [
            'photos' => $urls->forManagingBureau($profile),
            'maxPhotos' => (int) config('oppam.media.max_photos'),
            'hasHoroscope' => $profile->hasMedia(Profile::HOROSCOPE),
            'horoscopeLink' => null,
        ]);
    }

    protected function photoAddedMessage(): string
    {
        return __('Photo added. It will appear on the profile once our team has reviewed it.');
    }

    protected function photoProfile(): Profile
    {
        $staff = app(BureauGate::class)->authorize($this->photoActor(), BureauPermission::ProfilesEdit);

        return app(ManagedProfileScope::class)->find($staff, $this->profileCode);
    }

    protected function photoActor(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
