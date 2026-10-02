<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Escalations') }}</h1>
            <p>{{ __('Items moderators passed to a super admin, with their reason.') }}</p>
        </div>
    </div>

    @unless ($canDecide)
        <x-ui.alert type="info">{{ __('Only a super admin can decide escalated items.') }}</x-ui.alert>
    @endunless

    @if ($this->items->isEmpty())
        <div class="ui-card">
            <x-ui.empty-state icon="fa-level-up" :title="__('Nothing escalated')" />
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

        <x-admin.table :headers="[__('Type'), __('Profile'), __('Moderator’s reason'), __('Escalated'), '']" :caption="__('Escalated items')">
            @foreach ($this->items as $item)
                <tr wire:key="esc-{{ $item->id }}">
                    <td>{{ $item->type->label() }}</td>
                    <td>{{ $item->profile?->code }} · {{ $item->profile?->fullName() }}</td>
                    <td>{{ $item->reason_note }}</td>
                    <td>{{ $item->updated_at?->diffForHumans() }}</td>
                    <td class="text-end">
                        @if ($item->type === \App\Enums\ModerationItemType::ProfileNew)
                            @if ($item->profile)
                                <x-ui.button size="sm" :href="route('admin.moderation.profiles.review', ['profile' => $item->profile->code])">{{ __('Open review') }}</x-ui.button>
                            @endif
                        @elseif ($canDecide)
                            @if ($item->type === \App\Enums\ModerationItemType::Photo && $photos->get($item->subject_id)?->hasGeneratedConversion('thumb'))
                                <img src="{{ $photos->get($item->subject_id)->getUrl('thumb') }}" alt="" width="64" height="64" class="mod-esc-thumb" loading="lazy">
                            @endif
                            @php($seen = $item->type === \App\Enums\ModerationItemType::ProfileEdit ? $item->fieldsFingerprint() : '')
                            <x-ui.button size="sm" type="button" wire:click="approve('{{ $item->id }}', '{{ $seen }}')">{{ __('Approve') }}</x-ui.button>
                            <x-ui.button size="sm" variant="danger" type="button" wire:click="reject('{{ $item->id }}', '{{ $seen }}')">{{ __('Reject') }}</x-ui.button>
                        @endif
                    </td>
                </tr>
                @if (($edits[(string) $item->id] ?? []) !== [])
                    <tr wire:key="esc-diff-{{ $item->id }}">
                        <td colspan="5">
                            @foreach ($edits[(string) $item->id] as $row)
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
                        </td>
                    </tr>
                @endif
            @endforeach
        </x-admin.table>

        {{ $this->items->links() }}
    @endif
</div>
