<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Profile\Photos\DeleteHoroscope;
use App\Actions\Profile\Photos\DeleteProfilePhoto;
use App\Actions\Profile\Photos\MoveProfilePhoto;
use App\Actions\Profile\Photos\UpdatePhotoCaption;
use App\Actions\Profile\Photos\UploadHoroscope;
use App\Actions\Profile\Photos\UploadProfilePhoto;
use App\Models\Profile;
use App\Models\User;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The photo + horoscope manager's actions (M11), shared by the member's own manager and the
 * bureau's managed-profile form (§11A B.6, P7.3). Every change goes through the M11 Actions, which
 * authorize the actor on the profile (ProfilePolicy::managePhotos). The component says who acts
 * (photoActor) on which profile (photoProfile); the page is told "photos-changed" afterwards.
 * Use with Livewire's WithFileUploads.
 */
trait ManagesProfilePhotos
{
    /** Set by the cropper through $wire.upload('photo', …); validated by UploadProfilePhoto. */
    public ?TemporaryUploadedFile $photo = null;

    public ?TemporaryUploadedFile $horoscope = null;

    public ?string $status = null;

    abstract protected function photoActor(): User;

    abstract protected function photoProfile(): Profile;

    public function savePhoto(UploadProfilePhoto $upload): void
    {
        $photo = $this->photo;
        $this->photo = null;

        if (! $photo instanceof TemporaryUploadedFile) {
            $this->addError('photo', __('Choose a photo to upload.'));

            return;
        }

        $upload->handle($this->photoActor(), $this->photoProfile(), $photo);
        $this->photosChanged($this->photoAddedMessage());
    }

    public function deletePhoto(string $uuid, DeleteProfilePhoto $delete): void
    {
        $delete->handle($this->photoActor(), $this->photoProfile(), $uuid);
        $this->photosChanged(__('Photo deleted.'));
    }

    /** wire:sort handler and the "Make primary / Move" buttons. */
    public function movePhoto(string $uuid, int $position, MoveProfilePhoto $move): void
    {
        $move->handle($this->photoActor(), $this->photoProfile(), $uuid, $position);
        $this->photosChanged($position === 0 ? __('Primary photo updated.') : __('Photo order saved.'));
    }

    public function updateCaption(string $uuid, ?string $caption, UpdatePhotoCaption $update): void
    {
        $update->handle($this->photoActor(), $this->photoProfile(), $uuid, $caption);
        $this->photosChanged(__('Caption saved.'));
    }

    /** The horoscope uploads as soon as it is chosen (no crop step). */
    public function updatedHoroscope(): void
    {
        $file = $this->horoscope;
        $this->horoscope = null;

        if ($file instanceof TemporaryUploadedFile) {
            app(UploadHoroscope::class)->handle($this->photoActor(), $this->photoProfile(), $file);
            $this->photosChanged(__('Horoscope saved.'));
        }
    }

    public function deleteHoroscope(DeleteHoroscope $delete): void
    {
        $delete->handle($this->photoActor(), $this->photoProfile());
        $this->photosChanged(__('Horoscope removed.'));
    }

    /** Shown after an upload; a component may say it for its own audience. */
    protected function photoAddedMessage(): string
    {
        return __('Photo added. It will appear on your profile once it has been reviewed.');
    }

    private function photosChanged(string $message): void
    {
        $this->photoProfile()->unsetRelation('media');   // render reads the files as they are now
        $this->resetErrorBag();
        $this->status = $message;
        $this->dispatch('photos-changed');
    }
}
