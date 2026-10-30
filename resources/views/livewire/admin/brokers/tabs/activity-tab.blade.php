<div>
    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Staff login')" name="staff" id="at-staff" wire:model.live="staff" :options="$staffOptions" :placeholder="__('Everyone')" />
            </div>
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Activity')" name="group" id="at-group" wire:model.live="group" :options="$groups" :placeholder="__('All')" />
            </div>
            <div class="col-6 col-lg-3">
                <x-ui.input :label="__('From')" name="from" id="at-from" type="date" wire:model.live="from" />
            </div>
            <div class="col-6 col-lg-3">
                <x-ui.input :label="__('To')" name="to" id="at-to" type="date" wire:model.live="to" />
            </div>
        </div>
        <p class="small text-muted-brand mt-2 mb-0">{{ __('Without a staff or activity filter, actions our team took on the bureau are listed too.') }}</p>
    </div>

    <div wire:loading.class="opacity-50" wire:target="staff,group,from,to,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="[__('When'), __('Who'), __('What'), __('About')]" :caption="__('Activity')" :empty="$rows->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-history" :title="__('No activity')" :message="__('Nothing matches these filters.')" />
            </x-slot:emptyState>

            @foreach ($rows as $row)
                <tr wire:key="act-{{ $row->id }}">
                    <td>{{ $row->created_at->timezone($timezone)->format('d M Y, H:i') }}</td>
                    <td>
                        @if ($row->actor_type === \App\Enums\AuditActorType::Admin)
                            <x-ui.badge variant="verified">{{ __('Oppam team') }}</x-ui.badge>
                            <div class="small text-muted-brand">{{ $row->actor_label }}</div>
                        @else
                            {{ $codeByLogin[$row->actor_id] ?? '—' }}
                        @endif
                    </td>
                    <td>
                        {{ str_starts_with($row->action, 'broker.') ? \App\Queries\Broker\BureauActivity::label($row->action) : \Illuminate\Support\Str::of($row->action)->after('.')->replace('_', ' ')->ucfirst() }}
                        @if ($row->reason)<div class="small text-muted-brand">{{ $row->reason }}</div>@endif
                    </td>
                    <td>{{ $row->subject_label ?? '—' }}</td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $rows->links() }}
    </div>
</div>
