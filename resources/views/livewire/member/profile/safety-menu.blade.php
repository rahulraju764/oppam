{{--
    Block / Ignore / Report on another member's profile (PRD M09). A small "More" menu, then a
    confirmation dialog for each. Nothing here is shown to the other member.
--}}
<div class="safety-menu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <button type="button" class="send-int safety-menu__toggle" x-on:click="open = ! open" x-bind:aria-expanded="open"
            aria-haspopup="true" aria-controls="safety-menu-{{ $profileCode }}">
        <i class="fa fa-ellipsis-h" aria-hidden="true"></i>{{ __('More') }}
    </button>
    <ul class="safety-menu__list" id="safety-menu-{{ $profileCode }}" x-show="open" x-cloak>
        <li>
            <button type="button" class="safety-menu__item" x-on:click="open = false; $dispatch('open-modal', { name: 'report-profile' })">
                <i class="fa fa-flag" aria-hidden="true"></i>{{ __('Report :name', ['name' => $name]) }}
            </button>
        </li>
        <li>
            <button type="button" class="safety-menu__item" x-on:click="open = false; $dispatch('open-modal', { name: 'ignore-profile' })">
                <i class="fa fa-eye-slash" aria-hidden="true"></i>{{ __('Ignore') }}
            </button>
        </li>
        <li>
            <button type="button" class="safety-menu__item safety-menu__item--danger" x-on:click="open = false; $dispatch('open-modal', { name: 'block-profile' })">
                <i class="fa fa-ban" aria-hidden="true"></i>{{ __('Block') }}
            </button>
        </li>
    </ul>

    <x-ui.modal name="block-profile" :title="__('Block :name?', ['name' => $name])">
        <p>{{ __('You won’t see each other anywhere on Oppam. Likes, favourites and waiting interests between you are removed, and your conversation is closed. :name is not told.', ['name' => $name]) }}</p>
        <p class="form-text mb-0">{{ __('You can unblock later from Settings → Blocked & ignored.') }}</p>
        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" type="button" wire:click="block" loading="block" dusk="block-confirm">{{ __('Block') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="ignore-profile" :title="__('Ignore :name?', ['name' => $name])">
        <p class="mb-0">{{ __(':name won’t appear in your search, matches and lists any more. Nothing changes for them and they are not told.', ['name' => $name]) }}</p>
        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="button" wire:click="ignore" loading="ignore">{{ __('Ignore') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="report-profile" :title="__('Report :name', ['name' => $name])">
        <form wire:submit="report" id="reportProfileForm" novalidate>
            <x-ui.select :label="__('What is wrong?')" name="reportReason" id="profileReportReason" wire:model="reportReason"
                         :options="$reasons" :placeholder="__('Choose a reason')" error="reason" required />
            <div class="mt-3">
                <x-ui.textarea :label="__('Anything else we should know? (needed for “something else”)')" name="reportDetails" id="profileReportDetails"
                               wire:model="reportDetails" error="details" rows="3" maxlength="500" />
            </div>
            <p class="form-text mb-0 mt-2">{{ __('To report messages, use Report in your conversation — the messages you choose are attached.') }}</p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="reportProfileForm" loading="report">{{ __('Send report') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
