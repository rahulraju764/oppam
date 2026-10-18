{{--
    A13 flagged messages (PRD A13). Metadata only: no message text on this page — it is revealed on
    the review page, and that reveal is audited.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Flagged messages') }}</h1>
            <p>{{ __('Chat messages flagged automatically or reported by members. Open one to review it.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3">
            <div class="col-md-4">
                <x-ui.select :label="__('Show')" name="view" id="flagView" wire:model.live="view" :options="$views" />
            </div>
            <div class="col-md-4">
                <x-ui.select :label="__('Reason')" name="rule" id="flagRule" wire:model.live="rule" :options="$rules" :placeholder="__('All reasons')" />
            </div>
            <div class="col-md-4">
                <x-ui.select :label="__('Source')" name="source" id="flagSource" wire:model.live="source" :options="$sources" :placeholder="__('All sources')" />
            </div>
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="view, rule, source, gotoPage, nextPage, previousPage">
        <x-admin.table :headers="[__('Reason'), __('Source'), __('Sender'), __('Flagged'), __('Status'), '']" :caption="__('Flagged messages')" :empty="$this->flags->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-flag" :title="__('Nothing here')" :message="$view === 'waiting' ? __('No flagged messages are waiting.') : __('No flags match these filters.')" />
            </x-slot:emptyState>

            @foreach ($this->flags as $flag)
                <tr wire:key="flag-{{ $flag->id }}">
                    <td><x-ui.badge variant="warning" icon="fa-flag">{{ $flag->rule->label() }}</x-ui.badge></td>
                    <td>{{ $flag->source->label() }}</td>
                    <td>{{ $flag->sender?->code ?? '—' }}</td>
                    <td>{{ $flag->created_at?->setTimezone(config('oppam.display_timezone'))->format('d M Y, H:i') }}</td>
                    <td>
                        {{ $flag->status->label() }}
                        @if ($flag->decision)
                            <span class="d-block text-muted small">{{ $flag->decision->label() }}@if ($flag->decidedBy) · {{ $flag->decidedBy->name }}@endif</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <x-ui.button size="sm" :href="route('admin.chat.flags.review', ['flag' => $flag->id])" wire:navigate>{{ __('Review') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $this->flags->links() }}
    </div>
</div>
