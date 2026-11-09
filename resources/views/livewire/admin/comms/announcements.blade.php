{{--
    A14 announcements (P8.3a): member banners — live, scheduled or ended — with an editor drawer.
    Live ones reach open member pages at once; scheduled ones at their start time.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Announcements') }}</h1>
            <p>{{ __('A banner at the top of member pages, e.g. an offer. To also notify members by email or in the bell, use a campaign.') }}</p>
        </div>
        @if ($canBroadcast)
            <x-ui.button type="button" icon="fa-plus" wire:click="create" dusk="announcement-new">{{ __('New announcement') }}</x-ui.button>
        @endif
    </div>

    <div wire:loading.class="is-loading" wire:target="gotoPage, nextPage, previousPage, end">
        <x-admin.table :headers="[__('Announcement'), __('Audience'), __('When (IST)'), __('Status'), '']" :caption="__('Announcements')" :empty="$this->announcements->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-bullhorn" :title="__('No announcements yet')" :message="__('Create one to show a banner to members.')" />
            </x-slot:emptyState>

            @foreach ($this->announcements as $row)
                <tr wire:key="ann-{{ $row->code }}">
                    <td>
                        <strong>{{ $row->title }}</strong>
                        <div class="small text-muted-brand">{{ \Illuminate\Support\Str::limit($row->body, 90) }}</div>
                    </td>
                    <td>{{ $row->audience->label() }}</td>
                    <td class="small">
                        {{ $row->starts_at?->timezone($timezone)->format('d M, g:i a') ?? __('Now') }}
                        → {{ $row->ends_at?->timezone($timezone)->format('d M, g:i a') ?? __('until ended') }}
                    </td>
                    <td>
                        @if ($row->ended_at || ($row->ends_at && $row->ends_at->isPast()))
                            <x-ui.badge variant="muted">{{ __('Ended') }}</x-ui.badge>
                        @elseif ($row->isUpcoming())
                            <x-ui.badge variant="warning">{{ __('Scheduled') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">{{ __('Live') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-end">
                        @if ($canBroadcast && ! $row->ended_at && ! ($row->ends_at && $row->ends_at->isPast()))
                            <x-ui.button size="sm" variant="ghost" type="button" wire:click="edit('{{ $row->code }}')" wire:loading.attr="disabled" wire:target="edit">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button size="sm" variant="danger" type="button" wire:click="end('{{ $row->code }}')"
                                         wire:confirm="{{ __('End this announcement now? It leaves every member page.') }}" loading="end">{{ __('End now') }}</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $this->announcements->links() }}
    </div>

    <x-admin.drawer name="announcement-editor" :title="$editing ? __('Edit announcement') : __('New announcement')">
        @if ($editing !== null)
            <form wire:submit="save" novalidate id="announcementForm">
                <x-ui.input :label="__('Title')" name="title" id="annTitle" wire:model="title" required maxlength="150" />
                <x-ui.textarea :label="__('Message')" name="body" id="annBody" wire:model="body" rows="3" required :hint="__('Up to 500 characters.')" />
                <div class="row g-3">
                    <div class="col-md-6 col-12">
                        <x-ui.input :label="__('Button text')" name="actionText" id="annActionText" wire:model="actionText" maxlength="50" :hint="__('Optional.')" />
                    </div>
                    <div class="col-md-6 col-12">
                        <x-ui.input :label="__('Button link')" name="actionUrl" id="annActionUrl" wire:model="actionUrl" maxlength="255" :hint="__('A page like /plans, or an https:// address.')" />
                    </div>
                    <div class="col-md-6 col-12">
                        <x-ui.input :label="__('Starts (IST)')" name="startsAt" id="annStarts" type="datetime-local" wire:model="startsAt" :hint="__('Empty = now.')" />
                    </div>
                    <div class="col-md-6 col-12">
                        <x-ui.input :label="__('Ends (IST)')" name="endsAt" id="annEnds" type="datetime-local" wire:model="endsAt" :hint="__('Empty = until you end it.')" />
                    </div>
                    <div class="col-12">
                        <x-ui.select :label="__('Who sees it')" name="audience" id="annAudience" wire:model="audience" :options="$audiences" :selected="$audience" required />
                    </div>
                </div>
                <x-ui.checkbox name="isDismissible" id="annDismissible" wire:model="isDismissible">{{ __('Members can close it') }}</x-ui.checkbox>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <x-ui.button loading="save" dusk="announcement-save">{{ __('Save') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-drawer', { name: 'announcement-editor' })">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-admin.drawer>
</div>
