{{--
    A14 delivery log (P8.3a): every outbound email / SMS with its status and provider id. Recipients
    are masked; the message text is never stored.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Delivery log') }}</h1>
            <p>{{ __('Every email and SMS we sent, with what the provider reported. Kept for 180 days; message text is never stored.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-3 col-12">
                <x-ui.select :label="__('Channel')" name="channel" id="dlChannel" wire:model.live="channel" :options="$channels" :selected="$channel" :placeholder="__('All channels')" />
            </div>
            <div class="col-md-3 col-12">
                <x-ui.select :label="__('Status')" name="status" id="dlStatus" wire:model.live="status" :options="$statuses" :selected="$status" :placeholder="__('All statuses')" />
            </div>
            <div class="col-md-6 col-12">
                <x-ui.input :label="__('Member code')" name="member" id="dlMember" wire:model.live.debounce.400ms="member" maxlength="12" :hint="__('For example OPM12345.')" />
            </div>
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="channel, status, member, gotoPage, nextPage, previousPage, resend">
        <x-admin.table :headers="[__('Sent (IST)'), __('Channel'), __('To'), __('Message'), __('Status'), __('Provider id'), '']" :caption="__('Outbound messages')" :empty="$this->messages->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-envelope-open-o" :title="__('Nothing here')" :message="__('No messages match these filters.')" />
            </x-slot:emptyState>

            @foreach ($this->messages as $row)
                <tr wire:key="out-{{ $row->id }}">
                    <td class="small">{{ $row->sent_at->timezone($timezone)->format('d M, g:i a') }}</td>
                    <td>{{ $row->channel->label() }}</td>
                    <td><code>{{ $row->recipient_masked }}</code></td>
                    <td>
                        {{ $row->event ?? '—' }}
                        @if ($row->resent_from_id)<div class="small text-muted-brand">{{ __('Resend') }}</div>@endif
                    </td>
                    <td>
                        <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
                        @if ($row->error_message)<div class="small text-muted-brand">{{ $row->error_message }}</div>@endif
                    </td>
                    <td class="small"><code>{{ $row->provider_id ?? '—' }}</code></td>
                    <td class="text-end">
                        @if ($canResend && $row->isResendable())
                            <x-ui.button size="sm" variant="outline" type="button" wire:click="resend('{{ $row->id }}')" loading="resend">{{ __('Send again') }}</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $this->messages->links() }}
    </div>
</div>
