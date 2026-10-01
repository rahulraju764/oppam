<?php

declare(strict_types=1);

namespace App\Livewire\Member\Profile;

use App\Actions\Profile\Photos\DeleteHoroscope;
use App\Actions\Profile\Photos\DeleteProfilePhoto;
use App\Actions\Profile\Photos\MoveProfilePhoto;
use App\Actions\Profile\Photos\UpdatePhotoCaption;
use App\Actions\Profile\Photos\UploadHoroscope;
use App\Actions\Profile\Photos\UploadProfilePhoto;
use App\Domain\Media\HoroscopeAccess;
use App\Domain\Media\PhotoUrls;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * <livewire:member.profile.photo-manager /> — the member's own photos and horoscope (M11), used by
 * wizard step 6 and (P1.5) My Profile. Upload with progress after an in-browser crop, drag or
 * buttons to reorder, make primary, caption, delete. Every change goes through an Action; the
 * profile is always the signed-in member's own. Tells the page "photos-changed" afterwards.
 */
final class PhotoManager extends Component
{
    use WithFileUploads;

    /** Set by the cropper through $wire.upload('photo', …); validated by UploadProfilePhoto. */
    public ?TemporaryUploadedFile $photo = null;

    public ?TemporaryUploadedFile $horoscope = null;

    public ?string $status = null;

    public function savePhoto(UploadProfilePhoto $upload): void
    {
        $photo = $this->photo;
        $this->photo = null;

        if (! $photo instanceof TemporaryUploadedFile) {
            $this->addError('photo', __('Choose a photo to upload.'));

            return;
        }

        $upload->handle($this->member(), $this->profile(), $photo);
        $this->changed(__('Photo added. It will appear on your profile once it has been reviewed.'));
    }

    public function deletePhoto(string $uuid, DeleteProfilePhoto $delete): void
    {
        $delete->handle($this->member(), $this->profile(), $uuid);
        $this->changed(__('Photo deleted.'));
    }

    /** wire:sort handler and the "Make primary / Move" buttons. */
    public function movePhoto(string $uuid, int $position, MoveProfilePhoto $move): void
    {
        $move->handle($this->member(), $this->profile(), $uuid, $position);
        $this->changed($position === 0 ? __('Primary photo updated.') : __('Photo order saved.'));
    }

    public function updateCaption(string $uuid, ?string $caption, UpdatePhotoCaption $update): void
    {
        $update->handle($this->member(), $this->profile(), $uuid, $caption);
        $this->changed(__('Caption saved.'));
    }

    /** The horoscope uploads as soon as it is chosen (no crop step). */
    public function updatedHoroscope(): void
    {
        $file = $this->horoscope;
        $this->horoscope = null;

        if ($file instanceof TemporaryUploadedFile) {
            app(UploadHoroscope::class)->handle($this->member(), $this->profile(), $file);
            $this->changed(__('Horoscope saved.'));
        }
    }

    public function deleteHoroscope(DeleteHoroscope $delete): void
    {
        $delete->handle($this->member(), $this->profile());
        $this->changed(__('Horoscope removed.'));
    }

    public function render(PhotoUrls $urls, HoroscopeAccess $horoscope): View
    {
        $profile = $this->profile();
        $user = $this->member();

        return view('livewire.member.profile.photo-manager', [
            'photos' => $urls->forViewer($profile, $user),
            'maxPhotos' => (int) config('oppam.media.max_photos'),
            'horoscopeLink' => $horoscope->link($profile, $user),
        ]);
    }

    private function changed(string $message): void
    {
        $this->member()->profile?->unsetRelation('media');   // render reads the files as they are now
        $this->resetErrorBag();
        $this->status = $message;
        $this->dispatch('photos-changed');
    }

    private function profile(): Profile
    {
        return $this->member()->profile ?? abort(404);
    }

    private function member(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User || $user->role !== UserRole::Member) {
            abort(404);
        }

        return $user;
    }
}
