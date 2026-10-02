<div x-data="photoModeration" x-on:keydown="key($event)">
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Photo queue') }}</h1>
            <p>{{ __('Keyboard: ← → ↑ ↓ move · A approve · D reject · Space show / blur · Enter apply. Photos stay blurred until you show them.') }}</p>
        </div>
    </div>

    @if ($items->isEmpty())
        <div class="ui-card">
            <x-ui.empty-state icon="fa-picture-o" :title="__('All caught up')" :message="__('No photos are waiting for review.')" />
            <div class="text-center mt-2">
                <x-ui.button size="sm" variant="ghost" type="button" wire:click="refreshBatch" loading="refreshBatch" icon="fa-refresh">{{ __('Check again') }}</x-ui.button>
            </div>
        </div>
    @else
        <ul class="mod-grid" role="listbox" aria-multiselectable="true" aria-label="{{ __('Photos waiting for review') }}" x-ref="grid">
            @foreach ($items as $item)
                @php($photo = $photos->get($item->subject_id))
                <li class="mod-tile" role="option" x-bind:aria-selected="marks['{{ $item->id }}'] ? 'true' : 'false'" tabindex="{{ $loop->first ? 0 : -1 }}" wire:key="mod-{{ $item->id }}"
                    data-id="{{ $item->id }}" x-on:focus="focus = {{ $loop->index }}" x-on:click="focus = {{ $loop->index }}; $el.focus()"
                    x-bind:class="{ 'is-approve': marks['{{ $item->id }}'] === 'approve', 'is-reject': marks['{{ $item->id }}'] === 'reject', 'is-revealed': revealed['{{ $item->id }}'] }"
                    aria-label="{{ __('Photo from :code', ['code' => $item->profile?->code]) }}">
                    <img src="{{ $photo && $photo->hasGeneratedConversion('card') ? $photo->getUrl('card') : $placeholder }}" alt="" width="200" height="250" loading="lazy">
                    <div class="mod-tile__meta">
                        <strong>{{ $item->profile?->code }}</strong>
                        @if ($item->is_priority)<x-ui.badge variant="premium" icon="fa-diamond">{{ __('Paid') }}</x-ui.badge>@endif
                        @if (is_array($item->fields) && array_key_exists('caption', $item->fields))
                            <span class="d-block small">{{ __('New caption: “:c”', ['c' => $item->fields['caption']]) }}</span>
                        @elseif ($photo?->caption)
                            <span class="d-block small">{{ $photo->caption }}</span>
                        @endif
                        @foreach ($flags->get((string) $item->id, []) as $flag)
                            <x-ui.badge variant="warning" icon="fa-flag">{{ $flag['message'] ?? '' }}</x-ui.badge>
                        @endforeach
                        <span class="mod-tile__mark" x-text="marks['{{ $item->id }}'] === 'approve' ? @js(__('Approve')) : (marks['{{ $item->id }}'] === 'reject' ? @js(__('Reject')) : '')" aria-live="polite"></span>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($canDecide)
            <div class="ui-card mt-4 mod-batch">
                <x-ui.select :label="__('Reason for rejected photos')" name="reason" wire:model="reason" :options="$reasons" />
                <x-ui.textarea :label="__('Note to the member (optional)')" name="note" wire:model="note" error="note" rows="2" />
                <p class="mb-2" x-text="summary()"></p>
                <x-ui.button type="button" x-on:click="apply()" loading="decide" icon="fa-check">{{ __('Apply decisions (Enter)') }}</x-ui.button>
            </div>
        @endif
    @endif
</div>
