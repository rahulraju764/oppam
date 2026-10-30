{{--
    A07 consent disputes (§11A B.12, R-A07-10): list, record (brokers.edit), decide upheld / dismissed.
--}}
<div>
    @unless ($embedded)
        <div class="admin-page-head">
            <div>
                <h1>{{ __('Consent disputes') }}</h1>
                <p>{{ __('Clients who say they never agreed to a profile a bureau created. Recording one suspends the profile and opens a safety case.') }}</p>
            </div>
            @can('brokers.edit')
                <x-ui.button type="button" icon="fa-plus" x-on:click="$dispatch('open-modal', { name: 'dispute-record' })" dusk="dispute-record-open">{{ __('Record dispute') }}</x-ui.button>
            @endcan
        </div>
    @else
        @can('brokers.edit')
            <div class="mb-3">
                <x-ui.button size="sm" type="button" icon="fa-plus" x-on:click="$dispatch('open-modal', { name: 'dispute-record' })">{{ __('Record dispute') }}</x-ui.button>
            </div>
        @endcan
    @endunless

    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Status')" name="status" id="dp-status" wire:model.live="status" :options="$statuses" :placeholder="__('Any')" />
            </div>
            @unless ($embedded)
                <div class="col-6 col-lg-3">
                    <x-ui.input :label="__('Bureau code')" name="broker" id="dp-broker" wire:model.live.debounce.400ms="broker" placeholder="BRK1001" />
                </div>
            @endunless
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="status,broker,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="array_values(array_filter([__('Dispute'), $embedded ? null : __('Bureau'), __('Profile'), __('Complaint'), __('Case'), __('Status'), '']))"
                       :caption="__('Consent disputes')" :empty="$disputes->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-balance-scale" :title="__('No disputes')" :message="__('No consent dispute matches these filters.')" />
            </x-slot:emptyState>

            @foreach ($disputes as $dispute)
                <tr wire:key="dsp-{{ $dispute->id }}">
                    <td>
                        <strong>{{ $dispute->code }}</strong>
                        <div class="small text-muted-brand">{{ $dispute->created_at->timezone($timezone)->format('d M Y') }} · {{ $dispute->recordedBy?->name }}</div>
                    </td>
                    @unless ($embedded)
                        <td><a href="{{ route('admin.brokers.show', ['broker' => $dispute->broker?->code, 'tab' => 'disputes']) }}" wire:navigate>{{ $dispute->broker?->code }}</a></td>
                    @endunless
                    <td>
                        {{ $dispute->profile?->code }}
                        @if ($dispute->profile)<div><x-ui.badge :variant="$dispute->profile->status->badgeVariant()">{{ $dispute->profile->status->label() }}</x-ui.badge></div>@endif
                    </td>
                    <td>
                        <div class="small text-muted-brand">{{ $dispute->source->label() }}</div>
                        {{ $dispute->note }}
                        @if ($dispute->outcome_note)
                            <div class="small mt-1"><strong>{{ __('Outcome:') }}</strong> {{ $dispute->outcome_note }} <span class="text-muted-brand">· {{ $dispute->decidedBy?->name }}</span></div>
                        @endif
                    </td>
                    <td>
                        @if ($dispute->abuseCase)
                            @can('safety.reports.view')
                                <a href="{{ route('admin.safety.cases.show', ['case' => $dispute->abuseCase->code]) }}" wire:navigate>{{ $dispute->abuseCase->code }}</a>
                            @else
                                {{ $dispute->abuseCase->code }}
                            @endcan
                        @else
                            —
                        @endif
                    </td>
                    <td><x-ui.badge :variant="$dispute->status->badgeVariant()">{{ $dispute->status->label() }}</x-ui.badge></td>
                    <td class="text-end">
                        @if ($dispute->status === \App\Enums\ConsentDisputeStatus::Open)
                            @can('brokers.edit')
                                <x-ui.button size="sm" variant="outline" type="button" wire:click="decide('{{ $dispute->code }}')">{{ __('Decide') }}</x-ui.button>
                            @endcan
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $disputes->links() }}
    </div>

    @can('brokers.edit')
        <x-ui.modal name="dispute-record" :title="__('Record a consent dispute')">
            <div class="row g-3">
                @unless ($embedded)
                    <div class="col-6">
                        <x-ui.input :label="__('Bureau code')" name="newBroker" id="dp-new-broker" wire:model="newBroker" placeholder="BRK1001" required />
                    </div>
                @endunless
                <div class="col-6">
                    <x-ui.input :label="__('Profile code')" name="newProfile" id="dp-new-profile" wire:model="newProfile" placeholder="OPM12345" required />
                </div>
                <div class="col-6">
                    <x-ui.select :label="__('How it reached us')" name="newSource" id="dp-new-source" wire:model="newSource" :options="$sources" />
                </div>
            </div>
            <x-ui.textarea :label="__('What the client said')" name="note" id="dp-note" wire:model="note" rows="3" required
                           :hint="__('5–500 characters. Kept with the dispute and in the audit log.')" />
            <p class="small text-muted-brand mt-2 mb-0">{{ __('The profile is suspended now and a safety case is opened.') }}</p>
            <x-slot:footer>
                <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" type="button" wire:click="record" loading="record" dusk="dispute-record-save">{{ __('Record and suspend') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal name="dispute-decide" :title="__('Decide dispute :code', ['code' => $target])">
            <p>{{ __('Upheld: the client never agreed — the profile stays suspended, and enough upheld disputes pause the bureau’s adding and importing. Dismissed: the profile goes back to its earlier status.') }}</p>
            <x-ui.textarea :label="__('Outcome (recorded in the audit log)')" name="outcome" id="dp-outcome" wire:model="outcome" rows="3" required />
            <x-slot:footer>
                <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="outline" type="button" wire:click="dismiss" loading="dismiss">{{ __('Dismiss') }}</x-ui.button>
                <x-ui.button variant="danger" type="button" wire:click="uphold" loading="uphold">{{ __('Uphold') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</div>
