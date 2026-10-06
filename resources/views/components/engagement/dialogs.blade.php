{{--
    The two dialogs the like / favourite buttons open (PRD M06), once per member layout:
      "It's a match!"  — window event `its-a-match` { name, url, photo } from Shared\LikeButton
                         when a like completes a pair (acceptance: shown to the second liker at once);
      "Limit reached"  — window event `quota-reached` { title, message } when the plan's daily likes
                         or total favourites are used up (the server already refused).
    Text arrives through x-text / x-bind only — never as HTML.
--}}
@php($mutualUrl = route('member.likes', ['tab' => 'mutual']))
<div x-data="engagementDialog('its-a-match')" x-on:its-a-match.window="show($event)">
    <x-ui.modal name="its-a-match" :title="__('It’s a match!')">
        <div class="match-dialog">
            <img class="match-dialog__photo" x-bind:src="info.photo" x-show="info.photo" alt="" width="120" height="120">
            <p class="match-dialog__name" x-text="info.name"></p>
            <p class="mb-0">{{ __('You like each other. Open the profile and take the next step.') }}</p>
        </div>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="close()">{{ __('Keep browsing') }}</x-ui.button>
            {{-- Blade directives aren't compiled inside component attributes, so the fallback is echoed. --}}
            <x-ui.button :href="$mutualUrl" x-bind:href="info.url || {{ \Illuminate\Support\Js::from($mutualUrl) }}"
                         x-on:click="close()" wire:navigate>{{ __('View profile') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

<div x-data="engagementDialog('quota-reached')" x-on:quota-reached.window="show($event)">
    <x-ui.modal name="quota-reached" :title="__('Limit reached')">
        <p class="fw-semibold" x-text="info.title"></p>
        <p class="mb-0" x-text="info.message"></p>
        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="close()">{{ __('Not now') }}</x-ui.button>
            <x-ui.button :href="route('plans')" x-on:click="close()" wire:navigate>{{ __('See plans') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
