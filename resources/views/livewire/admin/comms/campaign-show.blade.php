{{--
    One A14 campaign (P8.3b1): numbers (live while sending), what it says, who it targets, and the
    next step — edit / test / send a draft, approve a large one, cancel.
--}}
<div @if ($row->status === \App\Enums\CampaignStatus::Sending) wire:poll.10s @endif>
    <div class="admin-page-head">
        <div>
            <h1>{{ $row->name }}</h1>
            <p>
                {{ $row->code }} · {{ $row->channel->label() }} ·
                <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
            </p>
        </div>
        <x-ui.button :href="route('admin.comms.campaigns.index')" variant="ghost" icon="fa-arrow-left" wire:navigate>{{ __('All campaigns') }}</x-ui.button>
    </div>

    @if ($row->status === \App\Enums\CampaignStatus::PendingApproval)
        <x-ui.alert type="warning" class="mb-3">
            {{ __(':count members is above the limit one admin may send to. A different admin with approval rights must approve it.', ['count' => number_format($row->recipient_count)]) }}
        </x-ui.alert>
    @elseif ($row->status === \App\Enums\CampaignStatus::Scheduled)
        <x-ui.alert type="info" class="mb-3">{{ __('Starts :when (IST).', ['when' => $row->scheduled_at?->timezone($timezone)->format('d M Y, g:i a')]) }}</x-ui.alert>
    @elseif ($row->status === \App\Enums\CampaignStatus::Sending)
        <x-ui.alert type="info" class="mb-3">{{ trans_choice('Sending — :count message a minute. These numbers refresh by themselves.|Sending — :count messages a minute. These numbers refresh by themselves.', $row->throttle_per_minute) }}</x-ui.alert>
    @endif

    @if ($row->status !== \App\Enums\CampaignStatus::Draft)
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3"><x-admin.stat-card :label="__('Audience')" :value="number_format($stats->audience)" :hint="trans_choice(':count not reached yet|:count not reached yet', $stats->pending)" :definition="__('Members fixed when it was sent.')" /></div>
            <div class="col-6 col-lg-3"><x-admin.stat-card :label="__('Sent')" :value="number_format($stats->sent)" :hint="trans_choice(':count skipped|:count skipped', $stats->skipped)" :definition="__('Handed to the channel. Skipped = no longer active, opted out, no verified email / phone any more, or cancelled before their turn.')" /></div>
            <div class="col-6 col-lg-3"><x-admin.stat-card :label="__('Delivered')" :value="number_format($stats->delivered)" :hint="__('Failed :f · bounced :b', ['f' => number_format($stats->failed), 'b' => number_format($stats->bounced)])" :definition="__('Confirmed by the email / SMS provider; in-app = in the member’s bell.')" /></div>
            <div class="col-6 col-lg-3">
                @if ($row->channel === \App\Enums\CommsChannel::Email)
                    <x-admin.stat-card :label="__('Opened · clicked')" :value="number_format($stats->opened).' · '.number_format($stats->clicked)" :hint="trans_choice(':count unsubscribed|:count unsubscribed', $stats->unsubscribed)" :definition="__('Opens come from the mail provider’s reports only (no tracking pixel); clicks are the button.')" />
                @else
                    <x-admin.stat-card :label="__('Clicked')" :value="$row->action_url ? number_format($stats->clicked) : '—'" :definition="__('Members who used the button.')" />
                @endif
            </div>
        </div>
        @if ($skippedBy !== [])
            <p class="small text-muted-brand mb-3">
                {{ __('Skipped:') }}
                @foreach ($skippedBy as $reason => $n)
                    {{ \App\Enums\CampaignSkipReason::tryFrom((string) $reason)?->label() }} {{ number_format($n) }}@if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <x-ui.card class="mb-3">
                <x-slot:header><h2 class="h5 mb-0">{{ __('Message') }}</h2></x-slot:header>
                <div class="ui-card tpl-preview">
                    @if ($row->channel === \App\Enums\CommsChannel::Sms)
                        <p class="small text-muted-brand mb-1">{{ __('DLT template :id', ['id' => $row->dlt_template_id]) }}</p>
                        <p class="mb-0">{{ $row->message }}</p>
                    @else
                        <p class="fw-semibold mb-2">{{ $row->subject }}</p>
                        @if ($row->channel === \App\Enums\CommsChannel::Email)
                            @foreach (preg_split('/\R{2,}/', trim($row->message)) ?: [] as $paragraph)
                                @if (trim($paragraph) !== ''){{ \App\Domain\Comms\TemplateCopy::markdown(trim($paragraph)) }}@endif
                            @endforeach
                        @else
                            <p class="mb-0">{{ $row->message }}</p>
                        @endif
                        @if ($row->action_url)
                            <p class="small mt-2 mb-0"><span class="chip">{{ $row->action_text }}</span> → {{ $row->action_url }}</p>
                        @endif
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card class="mb-3">
                <x-slot:header><h2 class="h5 mb-0">{{ __('Who it targets') }}</h2></x-slot:header>
                @if ($filters === [])
                    <p class="mb-0">{{ __('Every active member who can receive this channel.') }}</p>
                @else
                    <dl class="row mb-0">
                        @foreach ($filters as $label => $value)
                            <dt class="col-sm-5">{{ $label }}</dt><dd class="col-sm-7">{{ $value }}</dd>
                        @endforeach
                    </dl>
                @endif
            </x-ui.card>
        </div>

        <div class="col-12 col-xl-4">
            <x-ui.card class="mb-3">
                <x-slot:header><h2 class="h5 mb-0">{{ __('Next step') }}</h2></x-slot:header>
                <div class="d-grid gap-2">
                    @if ($row->status === \App\Enums\CampaignStatus::Draft && $canSend)
                        <x-ui.button type="button" wire:click="submit" loading="submit" dusk="campaign-submit"
                                     wire:confirm="{{ __('Send this campaign? The audience is fixed now. Large audiences go to a second admin for approval first.') }}">{{ $row->scheduled_at ? __('Schedule') : __('Send') }}</x-ui.button>
                        @if ($row->channel !== \App\Enums\CommsChannel::InApp)
                            <x-ui.button type="button" variant="outline" wire:click="sendTest" loading="sendTest" dusk="campaign-test">
                                {{ $row->channel === \App\Enums\CommsChannel::Email ? __('Send me a test email') : __('Send a test SMS to the test numbers') }}
                            </x-ui.button>
                        @endif
                        <x-ui.button :href="route('admin.comms.campaigns.edit', $row->code)" variant="ghost" wire:navigate>{{ __('Edit draft') }}</x-ui.button>
                    @endif
                    @if ($canApprove)
                        <x-ui.button type="button" wire:click="approve" loading="approve" dusk="campaign-approve"
                                     wire:confirm="{{ __('Approve sending to :count members?', ['count' => number_format($row->recipient_count)]) }}">{{ __('Approve') }}</x-ui.button>
                    @elseif ($row->status === \App\Enums\CampaignStatus::PendingApproval)
                        <p class="small text-muted-brand mb-0">{{ __('Waiting for a different admin to approve it.') }}</p>
                    @endif
                    @if ($canCancel)
                        <x-ui.button type="button" variant="danger" wire:click="cancel" loading="cancel" dusk="campaign-cancel"
                                     wire:confirm="{{ __('Cancel this campaign? Members not reached yet won’t get it. This can’t be undone.') }}">
                            {{ $row->status === \App\Enums\CampaignStatus::PendingApproval ? __('Decline (cancel)') : __('Cancel campaign') }}
                        </x-ui.button>
                    @endif
                    @if ($row->status->isFinal())
                        <p class="small text-muted-brand mb-0">{{ __('Finished campaigns can’t be sent again. Create a new one instead.') }}</p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header><h2 class="h5 mb-0">{{ __('Details') }}</h2></x-slot:header>
                <dl class="row small mb-0">
                    <dt class="col-6">{{ __('Created by') }}</dt><dd class="col-6">{{ $row->creator?->name ?? '—' }}</dd>
                    <dt class="col-6">{{ __('Approved by') }}</dt><dd class="col-6">{{ $row->approver?->name ?? '—' }}</dd>
                    <dt class="col-6">{{ __('Start (IST)') }}</dt><dd class="col-6">{{ $row->scheduled_at?->timezone($timezone)->format('d M Y, g:i a') ?? __('On send') }}</dd>
                    <dt class="col-6">{{ __('Started') }}</dt><dd class="col-6">{{ $row->started_at?->timezone($timezone)->format('d M, g:i a') ?? '—' }}</dd>
                    <dt class="col-6">{{ __('Finished') }}</dt><dd class="col-6">{{ ($row->completed_at ?? $row->cancelled_at)?->timezone($timezone)->format('d M, g:i a') ?? '—' }}</dd>
                    <dt class="col-6">{{ __('Per minute') }}</dt><dd class="col-6">{{ number_format($row->throttle_per_minute) }}</dd>
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
