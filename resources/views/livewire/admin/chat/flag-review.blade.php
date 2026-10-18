{{--
    A13 review of one flagged chat message. Before "Reveal message": metadata only. After: the
    flagged message and at most three messages either side in the same thread (never more of the
    conversation), and the masked / matched excerpt. The reveal is written to the audit log.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Review flagged message') }}</h1>
            <p>
                <a href="{{ route('admin.chat.flags') }}" wire:navigate><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ __('Back to flagged messages') }}</a>
            </p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="ui-card">
                <h2 class="admin-section-title">{{ __('About this flag') }}</h2>
                <dl class="mod-facts">
                    <dt>{{ __('Reason') }}</dt>
                    <dd><x-ui.badge variant="warning" icon="fa-flag">{{ $flag->rule->label() }}</x-ui.badge></dd>
                    <dt>{{ __('Source') }}</dt>
                    <dd>{{ $flag->source->label() }}</dd>
                    <dt>{{ __('Sender') }}</dt>
                    <dd>
                        @if ($senderUrl)
                            <a href="{{ $senderUrl }}" wire:navigate>{{ $flag->sender?->code }}</a>
                        @else
                            {{ $flag->sender?->code ?? '—' }}
                        @endif
                    </dd>
                    <dt>{{ __('Flagged') }}</dt>
                    <dd>{{ $flag->created_at?->setTimezone(config('oppam.display_timezone'))->format('d M Y, H:i') }}</dd>
                    <dt>{{ __('Status') }}</dt>
                    <dd>
                        {{ $flag->status->label() }}
                        @if ($flag->decision)
                            · {{ $flag->decision->label() }}@if ($flag->decidedBy) ({{ $flag->decidedBy->name }})@endif
                        @endif
                    </dd>
                    @if ($flag->report)
                        <dt>{{ __('Member’s reason') }}</dt>
                        <dd>{{ $flag->report->reason->label() }}</dd>
                    @endif
                    @if ($thread)
                        <dt>{{ __('Conversation') }}</dt>
                        <dd>
                            {{ $thread->status->label() }}
                            @if ($thread->frozen_reason) · {{ $thread->frozen_reason->label() }}@endif
                        </dd>
                    @endif
                    @if ($otherOpenFlags > 0)
                        <dt>{{ __('Other open flags') }}</dt>
                        <dd>{{ trans_choice(':count more on this message|:count more on this message', $otherOpenFlags, ['count' => $otherOpenFlags]) }}</dd>
                    @endif
                </dl>
            </div>

            @if ($decisions !== [])
                <div class="ui-card mt-4">
                    <h2 class="admin-section-title">{{ __('Decision') }}</h2>
                    @unless ($revealed)
                        <x-ui.alert type="info">{{ __('Reveal the message before deciding.') }}</x-ui.alert>
                    @else
                        <x-ui.textarea :label="__('Note (optional; recorded in the audit log)')" name="note" id="flagNote" wire:model="note" error="note" rows="2" />
                        <div class="admin-decisions mt-3">
                            @foreach ($decisions as $decision)
                                @if ($decision === \App\Enums\MessageFlagDecision::SuspendSender)
                                    <x-ui.button size="sm" variant="danger" type="button" x-on:click="$dispatch('open-modal', { name: 'suspend-sender' })">{{ $decision->label() }}</x-ui.button>
                                @else
                                    <x-ui.button size="sm" type="button" :variant="in_array($decision, [\App\Enums\MessageFlagDecision::RemoveMessage, \App\Enums\MessageFlagDecision::FreezeConversation], true) ? 'danger' : ($decision === \App\Enums\MessageFlagDecision::Dismiss ? 'outline' : 'primary')"
                                                 wire:click="decide('{{ $decision->value }}')" loading="decide">{{ $decision->label() }}</x-ui.button>
                                @endif
                            @endforeach
                        </div>
                    @endunless
                </div>
            @elseif ($escalatedLocked)
                <x-ui.alert type="info" class="mt-4">{{ __('Only a super admin can decide an escalated flag.') }}</x-ui.alert>
            @endif

            @if ($reportFrozen > 0 && $revealed)
                <div class="ui-card mt-4">
                    <h2 class="admin-section-title">{{ __('Frozen after reports') }}</h2>
                    <p class="small">{{ trans_choice(':count of this member’s conversations was frozen automatically after several reports.|:count of this member’s conversations were frozen automatically after several reports.', $reportFrozen, ['count' => $reportFrozen]) }}</p>
                    <x-ui.button size="sm" variant="outline" type="button" x-on:click="$dispatch('open-modal', { name: 'reopen-report-frozen' })">{{ __('Open them all again') }}</x-ui.button>
                </div>
                <x-admin.confirm-modal name="reopen-report-frozen" :title="__('Open the frozen conversations again')" action="reopenReportFrozen" reasonModel="freezeReason" :danger="false">
                    <p>{{ __('Every conversation of this member that was frozen after reports opens again. Conversations a moderator froze stay frozen.') }}</p>
                </x-admin.confirm-modal>
            @endif

            @if ($canFreeze && $revealed)
                <div class="ui-card mt-4">
                    <h2 class="admin-section-title">{{ __('Conversation') }}</h2>
                    <p class="small">{{ $thread->status === \App\Enums\ConversationStatus::Frozen ? __('The members can read this conversation but not write in it.') : __('Both members can write in this conversation.') }}</p>
                    <x-ui.button size="sm" variant="outline" type="button" x-on:click="$dispatch('open-modal', { name: 'freeze-thread' })">
                        {{ $thread->status === \App\Enums\ConversationStatus::Frozen ? __('Open conversation again') : __('Freeze conversation') }}
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="col-lg-7">
            <div class="ui-card">
                <h2 class="admin-section-title">{{ __('Message') }}</h2>
                @if (! $revealed)
                    <p>{{ __('Messages are private. Reveal only this message and the three messages around it — your view is recorded in the audit log.') }}</p>
                    <x-ui.button type="button" wire:click="reveal" loading="reveal" icon="fa-eye">{{ __('Reveal message') }}</x-ui.button>
                @else
                    @if ($context->excerpt)
                        <x-ui.alert type="warning">
                            <strong>{{ __('Matched / hidden text:') }}</strong> {{ $context->excerpt }}
                        </x-ui.alert>
                    @endif
                    <ol class="admin-chat-context" aria-label="{{ __('The flagged message with up to three messages before and after it') }}">
                        @foreach ([...$context->before, $context->flagged, ...$context->after] as $row)
                            <li @class(['admin-chat-context__item', 'is-flagged' => $row->isFlagged, 'is-gone' => $row->gone]) wire:key="ctx-{{ $row->id }}">
                                <span class="admin-chat-context__meta">
                                    {{ $row->senderLabel }} · {{ $row->at->format('d M, H:i') }}
                                    @if ($row->isFlagged)<x-ui.badge variant="warning">{{ __('Flagged') }}</x-ui.badge>@endif
                                </span>
                                @if ($row->isFlagged && $row->isImage)
                                    <img src="{{ route('admin.chat.flags.image', ['flag' => $flag->id]) }}" alt="{{ __('The flagged photo') }}" class="admin-chat-context__img" loading="lazy">
                                @else
                                    <span class="admin-chat-context__text">{{ $row->text }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    </div>

    <x-admin.confirm-modal name="suspend-sender" :title="__('Suspend the sender')" action="decide('{{ \App\Enums\MessageFlagDecision::SuspendSender->value }}')" reasonModel="note" :confirmLabel="__('Suspend')">
        <p>{{ __('The member can no longer sign in and every session ends. Their profile leaves search.') }}</p>
    </x-admin.confirm-modal>

    @if ($canFreeze)
        <x-admin.confirm-modal name="freeze-thread" :title="$thread?->status === \App\Enums\ConversationStatus::Frozen ? __('Open conversation again') : __('Freeze conversation')"
                               action="setFrozen({{ $thread?->status === \App\Enums\ConversationStatus::Frozen ? 'false' : 'true' }})" reasonModel="freezeReason" :danger="$thread?->status !== \App\Enums\ConversationStatus::Frozen">
            <p>{{ __('Both members see the change at once.') }}</p>
        </x-admin.confirm-modal>
    @endif
</div>
