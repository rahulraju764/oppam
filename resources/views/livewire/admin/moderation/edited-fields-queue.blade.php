<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Edited fields') }}</h1>
            <p>{{ __('Text changes to live profiles. Members keep showing the old text until you approve.') }}</p>
        </div>
    </div>

    @if ($this->items->isEmpty())
        <div class="ui-card">
            <x-ui.empty-state icon="fa-pencil-square-o" :title="__('All caught up')" :message="__('No edited texts are waiting.')" />
        </div>
    @else
        @if ($canDecide)
            <div class="ui-card mb-4">
                <div class="row g-3">
                    <div class="col-md-5"><x-ui.select :label="__('Reason when rejecting')" name="reason" wire:model="reason" :options="$reasons" /></div>
                    <div class="col-md-7"><x-ui.textarea :label="__('Note to the member (optional)')" name="note" wire:model="note" error="note" rows="2" /></div>
                </div>
            </div>
        @endif

        @foreach ($this->items as $item)
            <div class="ui-card mb-3" wire:key="edit-{{ $item->id }}">
                <div class="d-flex justify-content-between flex-wrap gap-2">
                    <h2 class="h6 mb-2">{{ $item->profile?->code }} · {{ $item->profile?->fullName() }}</h2>
                    <span class="text-muted-brand small">{{ $item->submitted_at->diffForHumans() }}</span>
                </div>

                @foreach ($rows[(string) $item->id] ?? [] as $row)
                    <div class="mb-2">
                        <strong>{{ $row['label'] }}</strong>
                        @foreach ($row['flags'] as $flag)
                            <x-ui.badge variant="warning" icon="fa-flag">{{ $flag }}</x-ui.badge>
                        @endforeach
                        <div class="mod-diff">
                            <del>{{ $row['old'] ?? '—' }}</del>
                            <ins>{{ $row['new'] }}</ins>
                        </div>
                    </div>
                @endforeach

                @if ($item->isClaimedByOther(auth('admin')->user()))
                    <x-ui.badge variant="warning" icon="fa-eye">{{ __('Being reviewed by :name', ['name' => $item->claimedBy?->name]) }}</x-ui.badge>
                @elseif ($canDecide)
                    <div class="d-flex gap-2">
                        <x-ui.button size="sm" type="button" wire:click="approve('{{ $item->id }}', '{{ $item->fieldsFingerprint() }}')" loading="approve" icon="fa-check">{{ __('Approve') }}</x-ui.button>
                        <x-ui.button size="sm" variant="danger" type="button" wire:click="reject('{{ $item->id }}', '{{ $item->fieldsFingerprint() }}')" loading="reject" icon="fa-times">{{ __('Reject') }}</x-ui.button>
                    </div>
                @endif
            </div>
        @endforeach

        {{ $this->items->links() }}
    @endif
</div>
