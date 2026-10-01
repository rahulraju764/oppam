{{--
    <livewire:member.profile.photo-manager /> — own photos + horoscope (M11; template profile-photos.php
    "Profile Photo / Additional Photos / Photo Caption"). First photo = primary. Drag (wire:sort) or the
    Move buttons reorder; the cropper (resources/js/alpine/photo-cropper.js) uploads with progress.
--}}
<div class="photo-manager" x-data="photoCropper"
     data-unreadable="{{ __('We couldn’t open this file. Please choose a JPG or PNG photo.') }}"
     data-failed="{{ __('The upload didn’t finish. Please check your connection and try again.') }}">

    <div class="form-group">
        <span class="form-label d-block" id="photos-label">{{ __('Profile Photos') }} *</span>
        <p class="form-text" id="photos-hint">
            {{ __('Add up to :max clear photos of yourself (JPG, PNG or WebP, at least 400 × 400). The first photo is your profile photo. Photos are shown after a quick review.', ['max' => $maxPhotos]) }}
        </p>

        @if ($photos === [])
            <x-ui.empty-state icon="fa-camera" :title="__('No photos yet')"
                :message="__('Profiles with photos get far more responses. Add at least one to submit your profile.')" />
        @else
            <ul class="photo-grid" wire:sort="movePhoto" aria-labelledby="photos-label" aria-describedby="photos-hint">
                @foreach ($photos as $index => $photo)
                    <li class="photo-tile" wire:key="photo-{{ $photo->uuid }}" wire:sort:item="{{ $photo->uuid }}">
                        <div class="photo-tile__image">
                            <img src="{{ $photo->cardUrl }}" alt="{{ $photo->processing ? __('Photo :n is being prepared', ['n' => $index + 1]) : ($photo->caption ?? __('Your photo :n', ['n' => $index + 1])) }}"
                                 width="300" height="375" loading="lazy">
                            @if ($index === 0)
                                <x-ui.badge variant="verified" icon="fa-star" class="photo-tile__primary">{{ __('Profile photo') }}</x-ui.badge>
                            @endif
                            @if ($photo->status)
                                <x-ui.badge :variant="$photo->status->badgeVariant()" class="photo-tile__status">{{ $photo->status->label() }}</x-ui.badge>
                            @endif
                        </div>

                        @if ($photo->rejectionReason)
                            <p class="form-text text-danger mb-1">{{ $photo->rejectionReason }}</p>
                        @endif

                        <label for="caption-{{ $photo->uuid }}" class="visually-hidden">{{ __('Caption for photo :n', ['n' => $index + 1]) }}</label>
                        <input id="caption-{{ $photo->uuid }}" name="caption-{{ $photo->uuid }}" type="text" class="form-control form-control-sm"
                               maxlength="100" value="{{ $photo->caption }}" placeholder="{{ __('Add a caption') }}"
                               x-on:change="$wire.updateCaption(@js($photo->uuid), $event.target.value)"
                               x-on:keydown.enter.prevent="$event.target.blur()">

                        <div class="photo-tile__actions">
                            @if ($index > 0)
                                <button type="button" class="ui-link-btn" wire:click="movePhoto(@js($photo->uuid), 0)">{{ __('Make profile photo') }}</button>
                                <button type="button" class="ui-icon-btn" wire:click="movePhoto(@js($photo->uuid), {{ $index - 1 }})"
                                        aria-label="{{ __('Move photo :n earlier', ['n' => $index + 1]) }}"><i class="fa fa-arrow-left" aria-hidden="true"></i></button>
                            @endif
                            @if (! $loop->last)
                                <button type="button" class="ui-icon-btn" wire:click="movePhoto(@js($photo->uuid), {{ $index + 1 }})"
                                        aria-label="{{ __('Move photo :n later', ['n' => $index + 1]) }}"><i class="fa fa-arrow-right" aria-hidden="true"></i></button>
                            @endif
                            <span x-data="{ sure: false }" class="photo-tile__delete">
                                <button type="button" class="ui-icon-btn is-danger" x-show="! sure" x-on:click="sure = true"
                                        aria-label="{{ __('Delete photo :n', ['n' => $index + 1]) }}"><i class="fa fa-trash" aria-hidden="true"></i></button>
                                <span x-show="sure" x-cloak>
                                    {{ __('Delete?') }}
                                    <button type="button" class="ui-link-btn is-danger" wire:click="deletePhoto(@js($photo->uuid))">{{ __('Yes') }}</button>
                                    <button type="button" class="ui-link-btn" x-on:click="sure = false">{{ __('No') }}</button>
                                </span>
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if (count($photos) < $maxPhotos)
            <label class="photo-add view-btn" for="photo-input">
                <i class="fa fa-plus" aria-hidden="true"></i> {{ count($photos) === 0 ? __('Add your profile photo') : __('Add another photo') }}
            </label>
            <input id="photo-input" name="photo-input" type="file" class="visually-hidden"
                   accept="image/jpeg,image/png,image/webp,image/heic,image/heif" x-on:change="choose($event)"
                   x-bind:disabled="uploading">
        @endif

        <div class="photo-progress" x-show="uploading" x-cloak role="status">
            <span>{{ __('Uploading…') }}</span>
            <div class="wizard-progress" role="progressbar" aria-label="{{ __('Upload progress') }}"
                 x-bind:aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                <span class="wizard-progress-bar" x-bind:style="'--progress: ' + progress + '%'"></span>
            </div>
        </div>

        <p class="invalid-feedback d-block" role="alert" x-show="error" x-text="error" x-cloak></p>
        @error('photo') <p class="invalid-feedback d-block" role="alert" id="photo-error">{{ $message }}</p> @enderror
        @error('caption') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
    </div>

    <div class="form-group">
        <span class="form-label d-block">{{ __('Horoscope') }}</span>
        @if ($horoscopeLink)
            <p class="mb-2">
                <a href="{{ $horoscopeLink }}" target="_blank" rel="noopener"><i class="fa fa-file-text-o" aria-hidden="true"></i> {{ __('View your horoscope') }}</a>
                <button type="button" class="ui-link-btn ms-2" wire:click="deleteHoroscope">{{ __('Remove') }}</button>
            </p>
        @endif
        <label for="horoscope-input" class="form-text d-block">{{ $horoscopeLink ? __('Replace with a new file (PDF, JPG or PNG, up to 5 MB)') : __('Upload a horoscope (PDF, JPG or PNG, up to 5 MB) — optional') }}</label>
        <input id="horoscope-input" name="horoscope-input" type="file" class="form-control" wire:model="horoscope" accept="application/pdf,image/jpeg,image/png">
        <span class="form-text" wire:loading wire:target="horoscope">{{ __('Uploading…') }}</span>
        @error('horoscope') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
    </div>

    <p class="form-text mb-0" aria-live="polite">{{ $status }}</p>

    <x-ui.modal name="photo-crop" :title="__('Crop your photo')">
        <div class="photo-crop">
            <img data-crop-image alt="{{ __('Photo to crop') }}">
        </div>
        <x-slot:footer>
            <button type="button" class="ui-icon-btn" x-on:click="rotate(-90)" aria-label="{{ __('Rotate left') }}"><i class="fa fa-rotate-left" aria-hidden="true"></i></button>
            <button type="button" class="ui-icon-btn" x-on:click="rotate(90)" aria-label="{{ __('Rotate right') }}"><i class="fa fa-rotate-right" aria-hidden="true"></i></button>
            <button type="button" class="ui-link-btn" x-on:click="cancel()">{{ __('Cancel') }}</button>
            <button type="button" class="view-btn" x-on:click="confirm()">{{ __('Use this photo') }}</button>
        </x-slot:footer>
    </x-ui.modal>
</div>
