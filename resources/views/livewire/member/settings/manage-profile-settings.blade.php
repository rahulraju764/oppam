{{--
    /settings → Hide / delete (M14, M16 — P8.2c). Four cards: hide / show my profile, download my
    data, our success story (answer a partner's story, share ours, withdraw), close my account.
    Every button calls its Action; stories are addressed by code.
--}}
<div>
    {{-- Hide / show --}}
    <div class="ui-card mb-4">
        <h2 class="safety-list__title">{{ __('Hide my profile') }}</h2>
        @if ($isHidden)
            <x-ui.alert type="info">{{ __('Your profile is hidden. Nobody can find or open it, and your matches and alerts are paused. Chats you already have still work.') }}</x-ui.alert>
            <x-ui.button type="button" variant="primary" wire:click="unhide" loading="unhide" dusk="manage-unhide">
                {{ __('Show my profile again') }}
            </x-ui.button>
        @else
            <p>{{ __('Taking a break? Hide your profile: nobody can find or open it until you show it again. Chats you already have keep working, and you can still answer interests you received. Nothing is deleted.') }}</p>
            @if ($canHide)
                <x-ui.button type="button" variant="outline" wire:click="hide" loading="hide" dusk="manage-hide">
                    {{ __('Hide my profile') }}
                </x-ui.button>
            @else
                <p class="form-text">{{ __('You can hide your profile once it is live. Profiles waiting for review aren’t shown to anyone yet.') }}</p>
            @endif
        @endif
    </div>

    {{-- Download my data --}}
    <div class="ui-card mb-4">
        <h2 class="safety-list__title">{{ __('Download my data') }}</h2>
        <p>{{ __('Get a copy of everything you have given us: your account and profile details, settings, saved searches, favourites, likes and interests, and your photos. We prepare it in the background and tell you when it’s ready; it can be downloaded for 24 hours.') }}</p>

        @if ($exports->isNotEmpty())
            <ul class="list-unstyled mb-3" aria-label="{{ __('Your recent copies') }}">
                @foreach ($exports as $export)
                    <li class="d-flex flex-wrap align-items-center gap-2 py-2" wire:key="export-{{ $export->code }}">
                        <span>{{ __('Asked on :date', ['date' => $export->created_at->timezone('Asia/Kolkata')->format('j M Y, g:i a')]) }}</span>
                        @if ($export->isDownloadable())
                            <x-ui.badge variant="success">{{ __('Ready until :time', ['time' => $export->expires_at?->timezone('Asia/Kolkata')->format('j M, g:i a')]) }}</x-ui.badge>
                            <x-ui.button variant="secondary" size="sm" icon="fa-download"
                                         :href="route('member.settings.data-export.download', ['code' => $export->code])">{{ __('Download') }}</x-ui.button>
                        @elseif ($export->status === \App\Enums\DataExportStatus::Queued)
                            <x-ui.badge variant="warning">{{ __('Being prepared') }}</x-ui.badge>
                        @elseif ($export->status === \App\Enums\DataExportStatus::Failed)
                            <x-ui.badge variant="danger">{{ __('Couldn’t be prepared — please ask again') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="muted">{{ __('Expired') }}</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <x-ui.button type="button" variant="outline" wire:click="requestExport" loading="requestExport" dusk="manage-export">
            {{ __('Prepare a copy of my data') }}
        </x-ui.button>
    </div>

    {{-- Success story (M16) --}}
    <div class="ui-card mb-4">
        <h2 class="safety-list__title">{{ __('Found your match on Oppam?') }}</h2>

        @foreach ($waitingForMe as $story)
            <div class="mb-3" wire:key="story-wait-{{ $story->code }}">
                <x-ui.alert type="info">
                    {{ __('Your partner shared your wedding story (:couple). It is published only if you agree too — after our team reviews it.', ['couple' => $story->couple]) }}
                </x-ui.alert>
                <blockquote class="mb-2">“{{ $story->quote }}”</blockquote>
                <p class="mb-2">{{ __('Wedding date: :date', ['date' => $story->wedding_date->format('j M Y')]) }} · {{ $story->place }}</p>
                <p class="mb-2 story-body-text">{{ $story->body }}</p>
                <p class="form-text">{{ __('Confirming also hides your profile, as you’ve found your match.') }}</p>
                <div class="d-flex flex-wrap gap-2">
                    <x-ui.button type="button" variant="primary" wire:click="answerStory('{{ $story->code }}', true)" loading="answerStory">
                        {{ __('Yes, share our story') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="outline" wire:click="answerStory('{{ $story->code }}', false)" loading="answerStory">
                        {{ __('No, don’t share it') }}
                    </x-ui.button>
                </div>
            </div>
        @endforeach

        @foreach ($myStories as $story)
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3" wire:key="story-mine-{{ $story->code }}">
                <span>{{ $story->couple }}</span>
                @if ($story->consent_b_at === null)
                    <x-ui.badge variant="warning">{{ __('Waiting for your partner') }}</x-ui.badge>
                @elseif ($story->status === \App\Enums\ContentStatus::Published)
                    <x-ui.badge variant="success">{{ __('On the site') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="default">{{ __('With our team for review') }}</x-ui.badge>
                @endif
                <x-ui.button type="button" variant="link" size="sm" wire:click="withdrawStory('{{ $story->code }}')" loading="withdrawStory">
                    {{ __('Withdraw') }}
                </x-ui.button>
            </div>
        @endforeach

        @if ($showStoryForm)
            <form wire:submit="submitStory" novalidate id="storyForm">
                <p>{{ __('Congratulations! Tell us about it. Your profile will be hidden (not deleted), and we’ll ask your partner to confirm before anything is published.') }}</p>
                <div class="row g-3">
                    <div class="col-md-6 col-12">
                        <x-ui.input :label="__('Your partner’s profile ID')" name="partnerCode" id="storyPartner" wire:model="partnerCode"
                                    :hint="__('For example OPM12345 — someone you connected with on Oppam.')" required autocomplete="off" />
                    </div>
                    <div class="col-md-6 col-12">
                        <x-ui.input :label="__('Wedding date')" name="weddingDate" id="storyDate" type="date" wire:model="weddingDate" required />
                    </div>
                    <div class="col-12">
                        <x-ui.input :label="__('Place')" name="place" id="storyPlace" wire:model="place" :hint="__('Optional, e.g. Thrissur')" />
                    </div>
                    <div class="col-12">
                        <x-ui.input :label="__('Your story in one line')" name="quote" id="storyQuote" wire:model="quote" required maxlength="300" />
                    </div>
                    <div class="col-12">
                        <x-ui.textarea :label="__('Your story')" name="storyBody" id="storyBody" wire:model="storyBody" required rows="5"
                                       :hint="__('At least 50 characters. How did you meet?')" />
                    </div>
                    <div class="col-12">
                        <x-ui.input :label="__('A photo of the two of you')" name="storyPhoto" id="storyPhoto" type="file" wire:model="storyPhoto"
                                    accept="image/jpeg,image/png,image/webp" :hint="__('Optional. JPG, PNG or WebP, up to 10 MB.')" />
                        <div wire:loading wire:target="storyPhoto" class="form-text">{{ __('Uploading…') }}</div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <x-ui.button variant="primary" loading="submitStory" dusk="story-submit">{{ __('Share our story') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancelStory">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        @elseif ($waitingForMe->isEmpty() && $myStories->isEmpty())
            <p>{{ __('Share your story and inspire other families. Your profile is hidden, not deleted.') }}</p>
            <x-ui.button type="button" variant="outline" wire:click="openStoryForm" dusk="story-open">{{ __('Share our story') }}</x-ui.button>
        @endif
    </div>

    {{-- Close account --}}
    <div class="ui-card mb-4">
        <h2 class="safety-list__title">{{ __('Close my account') }}</h2>
        <p>{{ __('Closing your account removes your profile from Oppam at once and signs you out everywhere. You have :days days to change your mind — just sign in again. After that, your details are erased for good.', ['days' => $restoreDays]) }}</p>
        <x-ui.button type="button" variant="danger" x-on:click="$dispatch('open-modal', { name: 'close-account' })" dusk="manage-close">
            {{ __('Close my account') }}
        </x-ui.button>
    </div>

    <x-ui.modal name="close-account" :title="__('Close my account')">
        <form wire:submit="closeAccount" novalidate id="closeAccountForm">
            <fieldset class="mb-3">
                <legend class="mat-label">{{ __('Why are you leaving?') }}</legend>
                @foreach ($reasons as $value => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="closeReason" id="closeReason-{{ $value }}" value="{{ $value }}"
                               wire:model.live="closeReason" @error('closeReason') aria-describedby="closeReason-error" @enderror>
                        <label class="form-check-label" for="closeReason-{{ $value }}">{{ $label }}</label>
                    </div>
                @endforeach
                @error('closeReason') <div class="invalid-feedback d-block" id="closeReason-error">{{ $message }}</div> @enderror
            </fieldset>

            @if ($closeReason === $storyReason)
                <x-ui.alert type="success">{{ __('Congratulations! You don’t need to close your account — share your story and we’ll hide your profile instead.') }}</x-ui.alert>
                <x-ui.button type="button" variant="primary" wire:click="openStoryForm">{{ __('Share our story') }}</x-ui.button>
            @else
                <x-ui.textarea :label="__('Anything you’d like to tell us?')" name="closeFeedback" id="closeFeedback" wire:model="closeFeedback" rows="3"
                               :hint="__('Optional — required for “Another reason”. Only our team reads it.')" />
                <x-ui.input :label="__('Your password')" name="closePassword" id="closePassword" type="password" wire:model="closePassword"
                            required autocomplete="current-password" />
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <x-ui.button variant="danger" loading="closeAccount" dusk="close-confirm">{{ __('Close my account') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', { name: 'close-account' })">{{ __('Keep my account') }}</x-ui.button>
                </div>
            @endif
        </form>
    </x-ui.modal>
</div>
