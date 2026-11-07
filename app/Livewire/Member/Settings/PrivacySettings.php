<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Actions\Profile\UpdatePrivacySettings;
use App\Data\Profile\PrivacySettingsData;
use App\Domain\Profile\Incognito;
use App\Enums\HoroscopeVisibility;
use App\Enums\PhoneVisibility;
use App\Enums\PhotoVisibility;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Privacy (PRD M14). Saves through UpdatePrivacySettings, which decides what may
 * change (Diamond-only incognito, rate limit, support sessions). The home-page opt-in is its own
 * nested component (SetHomeVisibility: audited consent). After a save that takes the member off
 * the online roster, this tab leaves it at once (`presence-hidden`, realtime.js).
 */
#[Lazy]
final class PrivacySettings extends Component
{
    public string $photoVisibility = '';

    public string $phoneVisibility = '';

    public string $horoscopeVisibility = '';

    public bool $showOnlineStatus = true;

    public bool $showLastSeen = true;

    public bool $readReceipts = true;

    public bool $hideFromSearch = false;

    public bool $incognito = false;

    public bool $contactFilter = false;

    public function mount(): void
    {
        $this->fill($this->stored());
    }

    public function placeholder(): View
    {
        return view('livewire.member.settings.placeholder');
    }

    public function save(UpdatePrivacySettings $update): void
    {
        $this->validate([
            'photoVisibility' => ['required', Rule::enum(PhotoVisibility::class)],
            'phoneVisibility' => ['required', Rule::enum(PhoneVisibility::class)],
            'horoscopeVisibility' => ['required', Rule::enum(HoroscopeVisibility::class)],
        ]);

        $wanted = new PrivacySettingsData(
            photoVisibility: PhotoVisibility::from($this->photoVisibility),
            phoneVisibility: PhoneVisibility::from($this->phoneVisibility),
            horoscopeVisibility: HoroscopeVisibility::from($this->horoscopeVisibility),
            showOnlineStatus: $this->showOnlineStatus,
            showLastSeen: $this->showLastSeen,
            readReceipts: $this->readReceipts,
            hideFromSearch: $this->hideFromSearch,
            incognito: $this->incognito,
            contactFilter: $this->contactFilter,
        );

        try {
            $hidden = $update->handle($this->member(), $wanted);
        } catch (SettingsRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', type: 'danger', title: __('Your profile isn’t available to change right now.'));

            return;
        }

        $this->fill($this->stored());
        if ($hidden) {
            $this->dispatch('presence-hidden');
        }
        $this->dispatch('toast', type: 'success', title: __('Your privacy settings are saved.'));
    }

    public function render(Incognito $incognito): View
    {
        $profile = $this->member()->profile ?? abort(404);

        return view('livewire.member.settings.privacy-settings', [
            'photoOptions' => PhotoVisibility::options(),
            'phoneOptions' => PhoneVisibility::options(),
            'horoscopeOptions' => HoroscopeVisibility::options(),
            'incognitoAvailable' => $incognito->isAvailable($profile),
        ]);
    }

    /** @return array<string, string|bool> the stored row, as this form's properties */
    private function stored(): array
    {
        $profile = $this->member()->profile ?? abort(404);
        $data = PrivacySettingsData::from(PrivacySetting::query()->whereKey($profile->id)->first());

        return [
            'photoVisibility' => $data->photoVisibility->value,
            'phoneVisibility' => $data->phoneVisibility->value,
            'horoscopeVisibility' => $data->horoscopeVisibility->value,
            'showOnlineStatus' => $data->showOnlineStatus,
            'showLastSeen' => $data->showLastSeen,
            'readReceipts' => $data->readReceipts,
            'hideFromSearch' => $data->hideFromSearch,
            'incognito' => $data->incognito,
            'contactFilter' => $data->contactFilter,
        ];
    }

    private function member(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 404);

        return $user;
    }
}
