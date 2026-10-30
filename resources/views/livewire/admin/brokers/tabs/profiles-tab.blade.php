<div>
    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Status')" name="status" id="pt-status" wire:model.live="status" :options="$statuses" :placeholder="__('Any')" />
            </div>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="status,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="[__('Profile'), __('Name'), __('Status'), __('Assigned to'), __('Source'), __('Updated')]" :caption="__('Managed profiles')" :empty="$profiles->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-users" :title="__('No managed profiles')" :message="__('This bureau manages no profile with this status.')" />
            </x-slot:emptyState>

            @foreach ($profiles as $profile)
                <tr wire:key="mp-{{ $profile->id }}">
                    <td>
                        @if ($profile->status === \App\Enums\ProfileStatus::PendingReview)
                            @can('moderation.view')
                                <a href="{{ route('admin.moderation.profiles.review', ['profile' => $profile->code]) }}" wire:navigate>{{ $profile->code }}</a>
                                <div class="small text-muted-brand">{{ __('Review in moderation') }}</div>
                            @else
                                {{ $profile->code }}
                            @endcan
                        @else
                            @can('members.view')
                                <a href="{{ route('admin.members.show', ['profile' => $profile->code]) }}" wire:navigate>{{ $profile->code }}</a>
                            @else
                                {{ $profile->code }}
                            @endcan
                        @endif
                    </td>
                    <td>{{ trim($profile->first_name.' '.$profile->last_name) }}</td>
                    <td><x-ui.badge :variant="$profile->status->badgeVariant()">{{ $profile->status->label() }}</x-ui.badge></td>
                    <td>{{ $profile->assignedStaff?->code ?? __('Unassigned') }}</td>
                    <td>{{ $profile->import_batch_id ? __('Bulk import') : __('Single form') }}</td>
                    <td>{{ $profile->updated_at->timezone($timezone)->format('d M Y') }}</td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $profiles->links() }}
    </div>
</div>
