<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Models\Media;
use App\Models\Profile;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A03 "Photos": every photo of the member with its moderation status, caption and rejection
 * reason (read only — decisions are made in the A04 photo queue).
 */
final class PhotosTab extends Component
{
    use IsMemberTab;

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function render(): View
    {
        $profile = $this->profile();

        return view('livewire.admin.members.tabs.photos-tab', [
            'photos' => Media::query()
                ->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)
                ->where('collection_name', Profile::PHOTOS)->orderBy('order_column')->get(),
        ]);
    }
}
