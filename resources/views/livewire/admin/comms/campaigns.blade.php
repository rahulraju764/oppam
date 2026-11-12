{{--
    A14 campaigns (P8.3b1): every campaign, its status and headline numbers. A row opens its page.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Campaigns') }}</h1>
            <p>{{ __('One message on one channel to a segment of members. Members who switched off offers on that channel are never included.') }}</p>
        </div>
        @if ($canSend)
            <x-ui.button :href="route('admin.comms.campaigns.builder')" icon="fa-plus" wire:navigate dusk="campaign-new">{{ __('New campaign') }}</x-ui.button>
        @endif
    </div>

    @if ($awaitingApproval > 0)
        <x-ui.alert type="warning" class="mb-3">
            {{ trans_choice(':count campaign is waiting for approval.|:count campaigns are waiting for approval.', $awaitingApproval) }}
            <x-ui.button type="button" variant="link" size="sm" wire:click="$set('status', 'PENDING_APPROVAL')">{{ __('Show them') }}</x-ui.button>
        </x-ui.alert>
    @endif

    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <x-ui.select :label="__('Status')" name="campaignStatus" id="campaign-status" wire:model.live="status" :options="$statuses" :placeholder="__('All')" :selected="$status" />
            </div>
            <div class="col-12 col-md-4">
                <x-ui.select :label="__('Channel')" name="campaignChannel" id="campaign-channel" wire:model.live="channel" :options="$channels" :placeholder="__('All')" :selected="$channel" />
            </div>
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="status, channel, gotoPage, nextPage, previousPage">
        <x-admin.table :headers="[__('Campaign'), __('Channel'), __('Audience'), __('Sent'), __('Opened'), __('Clicked'), __('Status'), __('Created (IST)')]"
                       :caption="__('Campaigns')" :empty="$this->campaigns->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-paper-plane-o"
                                  :title="$status !== '' || $channel !== '' ? __('No campaigns match these filters') : __('No campaigns yet')"
                                  :message="$canSend ? __('Create one to reach a segment of members by email, SMS or in the bell.') : __('Campaigns your team sends appear here.')" />
            </x-slot:emptyState>

            @foreach ($this->campaigns as $row)
                @php($s = $stats[$row->id])
                <tr wire:key="cmp-{{ $row->code }}">
                    <td>
                        <a href="{{ route('admin.comms.campaigns.show', $row->code) }}" wire:navigate><strong>{{ $row->name }}</strong></a>
                        <div class="small text-muted-brand">{{ $row->code }} · {{ $row->creator?->name }}</div>
                    </td>
                    <td>{{ $row->channel->label() }}</td>
                    <td>{{ $s->audience > 0 ? number_format($s->audience) : '—' }}</td>
                    <td>{{ number_format($s->sent) }}</td>
                    <td>{{ $row->channel === \App\Enums\CommsChannel::Email ? number_format($s->opened) : '—' }}</td>
                    <td>{{ $row->action_url ? number_format($s->clicked) : '—' }}</td>
                    <td><x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge></td>
                    <td class="small">{{ $row->created_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $this->campaigns->links() }}
    </div>
</div>
