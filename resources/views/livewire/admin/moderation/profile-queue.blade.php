<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Profile queue') }}</h1>
            <p>{{ __('New profiles waiting for review. Paid members first, then oldest first. Opening a profile reserves it for you for :minutes minutes.', ['minutes' => config('moderation.claim_minutes')]) }}</p>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="lane" name="lane" wire:model.live="priorityOnly">
            <label class="form-check-label" for="lane">{{ __('Paid lane only') }}</label>
        </div>
    </div>

    @if (session('status'))
        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
    @endif

    <x-admin.table :headers="[__('Profile'), __('Member'), __('Lane'), __('Waiting since (IST)'), __('Reviewer'), '']" :caption="__('Profiles waiting for review')" :empty="$this->items->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-check-square-o" :title="__('All caught up')" :message="__('No profiles are waiting for review.')" />
        </x-slot:emptyState>

        @foreach ($this->items as $item)
            <tr wire:key="item-{{ $item->id }}">
                <td><strong>{{ $item->profile?->code }}</strong></td>
                <td>{{ $item->profile?->fullName() }} <span class="text-muted-brand">{{ $item->profile?->gender->label() }}, {{ $item->profile?->age() }}</span></td>
                <td>
                    @if ($item->is_priority)
                        <x-ui.badge variant="premium" icon="fa-diamond">{{ __('Paid') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="muted">{{ __('Standard') }}</x-ui.badge>
                    @endif
                </td>
                <td>{{ $item->submitted_at->timezone(config('oppam.display_timezone'))->format('d M, g:i a') }}</td>
                <td>
                    @if ($item->claimed_until?->isFuture())
                        <x-ui.badge variant="warning" icon="fa-eye">{{ __('Being reviewed by :name', ['name' => $item->claimedBy?->name]) }}</x-ui.badge>
                    @endif
                </td>
                <td class="text-end">
                    @if ($item->profile)
                        <x-ui.button size="sm" :href="route('admin.moderation.profiles.review', ['profile' => $item->profile->code])">{{ __('Review') }}</x-ui.button>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $this->items->links() }}
</div>
