<div>
    <p class="text-muted-brand">
        {{ __(':total referred · :month this month · :converted converted', ['total' => $stats['total'], 'month' => $stats['this_month'], 'converted' => $stats['converted']]) }}
    </p>

    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Stage')" name="stage" id="rt-stage" wire:model.live="stage" :options="$stages" :placeholder="__('Any')" />
            </div>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="stage,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="[__('Profile'), __('Joined'), __('Stage'), __('Commission')]" :caption="__('Referrals')" :empty="$rows->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-share-alt" :title="__('No referrals')" :message="__('No member has joined with this bureau’s code yet.')" />
            </x-slot:emptyState>

            @foreach ($rows as $row)
                <tr wire:key="ref-{{ $row->id }}">
                    <td>
                        @can('members.view')
                            <a href="{{ route('admin.members.show', ['profile' => $row->getAttribute('profile_code')]) }}" wire:navigate>{{ $row->getAttribute('profile_code') }}</a>
                        @else
                            {{ $row->getAttribute('profile_code') }}
                        @endcan
                    </td>
                    <td>{{ $row->created_at->timezone($timezone)->format('d M Y') }}</td>
                    <td><x-ui.badge :variant="$row->getAttribute('stage')->badge()">{{ $row->getAttribute('stage')->label() }}</x-ui.badge></td>
                    <td>{{ $row->commission_paise ? \App\ValueObjects\Money::paise($row->commission_paise)->formatWithSymbol() : '—' }}</td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $rows->links() }}
    </div>
</div>
