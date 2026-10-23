{{--
    A10 review of one abuse case. Before "Reveal evidence": reports and metadata only. After: for
    each chat report, the messages the member selected and a few around each (never the rest of a
    conversation). The reveal is written to the audit log.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Safety case :code', ['code' => $case->code]) }}</h1>
            <p><a href="{{ route('admin.safety.cases') }}" wire:navigate><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ __('Back to safety cases') }}</a></p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="ui-card">
                <h2 class="admin-section-title">{{ __('Case') }}</h2>
                <dl class="mod-facts">
                    <dt>{{ __('Status') }}</dt>
                    <dd>
                        <x-ui.badge :variant="$case->status->badgeVariant()">{{ $case->status->label() }}</x-ui.badge>
                        @if ($case->outcome)<x-ui.badge :variant="$case->outcome->badgeVariant()">{{ $case->outcome->label() }}</x-ui.badge>@endif
                    </dd>
                    <dt>{{ __('Priority') }}</dt>
                    <dd>
                        <x-ui.badge :variant="$case->priority->badgeVariant()">{{ $case->priority->label() }}</x-ui.badge>
                        @if ($case->escalated_at)<x-ui.badge variant="danger" icon="fa-level-up">{{ __('Escalated :date', ['date' => $case->escalated_at->timezone($timezone)->format('d M, g:i a')]) }}</x-ui.badge>@endif
                    </dd>
                    <dt>{{ __('Act by (IST)') }}</dt>
                    <dd>{{ $case->sla_due_at->timezone($timezone)->format('d M Y, g:i a') }}
                        @if ($case->isOverdue())<x-ui.badge variant="danger" icon="fa-clock-o">{{ __('Overdue') }}</x-ui.badge>@endif</dd>
                    <dt>{{ __('Reports') }}</dt>
                    <dd>{{ trans_choice(':count report from|:count reports from', $case->reports_count, ['count' => $case->reports_count]) }} {{ trans_choice(':count member|:count members', $case->reporters_count, ['count' => $case->reporters_count]) }}</dd>
                    <dt>{{ __('Assigned to') }}</dt>
                    <dd>{{ $case->assignee?->name ?? '—' }}</dd>
                    @if ($case->decided_at)
                        <dt>{{ __('Decided') }}</dt>
                        <dd>{{ $case->decided_at->timezone($timezone)->format('d M Y, g:i a') }} · {{ $case->decidedBy?->name }}
                            @if ($case->outcome_note)<span class="d-block small">{{ $case->outcome_note }}</span>@endif</dd>
                    @endif
                </dl>
                @if ($canTriage)
                    <div class="admin-decisions">
                        <x-ui.button size="sm" variant="outline" type="button" wire:click="assignToMe" loading="assignToMe">{{ __('Assign to me') }}</x-ui.button>
                        <x-ui.button size="sm" variant="outline" type="button" x-on:click="$dispatch('open-modal', { name: 'case-priority' })">{{ __('Change priority') }}</x-ui.button>
                    </div>
                @endif
            </div>

            <div class="ui-card mt-4">
                <h2 class="admin-section-title">{{ __('Member') }}</h2>
                @if ($profile)
                    <dl class="mod-facts">
                        <dt>{{ __('Profile') }}</dt>
                        <dd>@if ($memberUrl)<a href="{{ $memberUrl }}" wire:navigate>{{ $profile->code }}</a>@else{{ $profile->code }}@endif · {{ $profile->fullName() }}</dd>
                        <dt>{{ __('Profile status') }}</dt>
                        <dd>{{ $profile->status->label() }} @if ($profile->user) · {{ $profile->user->status->label() }}@endif</dd>
                        <dt>{{ __('Member since') }}</dt>
                        <dd>{{ $profile->user?->created_at?->timezone($timezone)->format('d M Y') ?? '—' }}</dd>
                        <dt>{{ __('Verified') }}</dt>
                        <dd>{{ $profile->is_verified ? __('Yes') : __('No') }}</dd>
                        @if ($profile->legal_hold_at)
                            <dt>{{ __('Legal hold') }}</dt>
                            <dd><x-ui.badge variant="danger" icon="fa-gavel">{{ __('Since :date', ['date' => $profile->legal_hold_at->timezone($timezone)->format('d M Y')]) }}</x-ui.badge></dd>
                        @endif
                    </dl>
                @else
                    <p class="text-muted-brand">{{ __('This profile no longer exists.') }}</p>
                @endif
            </div>

            <div class="ui-card mt-4">
                <h2 class="admin-section-title">{{ __('History') }}</h2>
                @forelse ($history as $action)
                    <p class="small mb-2" wire:key="hist-{{ $action->id }}">
                        <x-ui.badge :variant="$action->outcome->badgeVariant()">{{ $action->outcome->label() }}</x-ui.badge>
                        {{ $action->created_at?->timezone($timezone)->format('d M Y') }} · {{ $action->admin?->name ?? __('System') }}
                    </p>
                @empty
                    <p class="small text-muted-brand mb-2">{{ __('No earlier safety actions.') }}</p>
                @endforelse
                @if ($earlierCases->isNotEmpty())
                    <p class="small mb-1"><strong>{{ __('Earlier cases') }}</strong></p>
                    <ul class="small list-unstyled mb-0">
                        @foreach ($earlierCases as $earlier)
                            <li wire:key="earlier-{{ $earlier->code }}"><a href="{{ route('admin.safety.cases.show', ['case' => $earlier->code]) }}" wire:navigate>{{ $earlier->code }}</a>
                                · {{ $earlier->outcome?->label() ?? $earlier->status->label() }} · {{ $earlier->created_at?->timezone($timezone)->format('d M Y') }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="ui-card mt-4">
                <h2 class="admin-section-title">{{ __('Blocks (read only)') }}</h2>
                <p class="small">{{ trans_choice('Blocked by :count member.|Blocked by :count members.', $blockedBy, ['count' => $blockedBy]) }}
                    {{ trans_choice('Has blocked :count member.|Has blocked :count members.', $blocking, ['count' => $blocking]) }}</p>
                @if ($recentBlockers->isNotEmpty())
                    <ul class="small list-unstyled mb-0">
                        @foreach ($recentBlockers as $block)
                            <li wire:key="blk-{{ $block->id }}">{{ $block->blocker?->code }} · {{ $block->created_at?->timezone($timezone)->format('d M Y') }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($isSuperAdmin && $profile)
                <div class="ui-card mt-4">
                    <h2 class="admin-section-title">{{ __('Legal hold') }}</h2>
                    <p class="small">{{ $profile->legal_hold_at ? __('Purges are paused for this member until the hold is lifted.') : __('Keep this member’s data (account, chats, ID documents) for a legal request.') }}</p>
                    <div class="admin-decisions">
                        <x-ui.button size="sm" variant="outline" type="button" x-on:click="$dispatch('open-modal', { name: 'legal-hold' })">
                            {{ $profile->legal_hold_at ? __('Lift legal hold') : __('Place legal hold') }}
                        </x-ui.button>
                        @if ($evidencePackUrl)
                            <x-ui.button size="sm" type="button" :href="$evidencePackUrl" icon="fa-download">{{ __('Download evidence pack') }}</x-ui.button>
                        @endif
                    </div>
                </div>
                <x-admin.confirm-modal name="legal-hold" :title="$profile->legal_hold_at ? __('Lift legal hold') : __('Place legal hold')"
                                       action="setHold({{ $profile->legal_hold_at ? 'false' : 'true' }})" reasonModel="holdReason" :danger="false">
                    <p>{{ __('Recorded in the audit log.') }}</p>
                </x-admin.confirm-modal>
            @endif
        </div>

        <div class="col-lg-7">
            <div class="ui-card">
                <h2 class="admin-section-title">{{ __('Reports') }}</h2>
                <ol class="case-reports">
                    @foreach ($reports as $report)
                        <li class="case-reports__item" wire:key="report-{{ $report->id }}">
                            <span class="case-reports__meta">
                                <x-ui.badge :variant="$report->reason->priority()->badgeVariant()">{{ $report->reason->label() }}</x-ui.badge>
                                {{ $report->source->label() }} · {{ __('by :code', ['code' => $report->reporter?->code ?? '—']) }}
                                · {{ $report->created_at?->timezone($timezone)->format('d M, g:i a') }} · {{ $report->status->label() }}
                            </span>
                            @if ($report->details)<p class="case-reports__details">{{ $report->details }}</p>@endif
                            @if ($report->messages->isNotEmpty())
                                <span class="small text-muted-brand">{{ trans_choice(':count message attached|:count messages attached', $report->messages->count(), ['count' => $report->messages->count()]) }}</span>
                            @endif
                        </li>
                    @endforeach
                    @if ($reports->isEmpty())
                        <li class="small text-muted-brand">{{ __('Opened by an escalation from chat safety — no member report.') }}</li>
                    @endif
                </ol>
            </div>

            <div class="ui-card mt-4">
                <h2 class="admin-section-title">{{ __('Conversation evidence') }}</h2>
                @if (! $revealed)
                    <p>{{ __('Messages are private. Reveal only the reported messages and the few around each — your view is recorded in the audit log.') }}</p>
                    <x-ui.button type="button" wire:click="reveal" loading="reveal" icon="fa-eye">{{ __('Reveal evidence') }}</x-ui.button>
                @elseif ($evidence === [])
                    <p class="small text-muted-brand mb-0">{{ __('No chat messages were reported in this case.') }}</p>
                @else
                    @foreach ($evidence as $item)
                        <h3 class="h6 mt-3">{{ __(':reason — reported by :code', ['reason' => $item->reasonLabel, 'code' => $item->reporterCode]) }}</h3>
                        <ol class="admin-chat-context" aria-label="{{ __('Reported messages with the messages around them') }}">
                            @foreach ($item->messages as $row)
                                <li @class(['admin-chat-context__item', 'is-flagged' => $row->isFlagged, 'is-gone' => $row->gone]) wire:key="ev-{{ $item->reportId }}-{{ $row->id }}">
                                    <span class="admin-chat-context__meta">
                                        {{ $row->senderLabel }} · {{ $row->at->format('d M, H:i') }}
                                        @if ($row->isFlagged)<x-ui.badge variant="warning">{{ __('Reported') }}</x-ui.badge>@endif
                                    </span>
                                    <span class="admin-chat-context__text">{{ $row->text }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @endforeach
                @endif
            </div>

            <div class="ui-card mt-4">
                <h2 class="admin-section-title">{{ __('Photos') }}</h2>
                @if ($photoList->isEmpty())
                    <p class="small text-muted-brand mb-0">{{ __('No photos.') }}</p>
                @else
                    <ul class="mod-photo-grid list-unstyled">
                        @foreach ($photoList as $photo)
                            <li wire:key="cphoto-{{ $photo->uuid }}">
                                @if ($photo->hasGeneratedConversion('thumb'))
                                    <img src="{{ $photo->getUrl('thumb') }}" alt="{{ __('Photo :n', ['n' => $loop->iteration]) }}" width="200" height="200" loading="lazy">
                                @else
                                    <span class="text-muted-brand small">{{ __('Processing…') }}</span>
                                @endif
                                <div class="small mt-1">
                                    <x-ui.badge :variant="$photo->moderation_status->badgeVariant()">{{ $photo->moderation_status->label() }}</x-ui.badge>
                                    @if ($outcomes !== [] && $photo->moderation_status !== \App\Enums\PhotoStatus::Rejected)
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" id="remove-{{ $photo->uuid }}" name="photos[]" value="{{ $photo->uuid }}" wire:model="photos">
                                            <label class="form-check-label" for="remove-{{ $photo->uuid }}">{{ __('Remove with “Remove content”') }}</label>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($outcomes !== [])
                <div class="ui-card mt-4">
                    <h2 class="admin-section-title">{{ __('Decision') }}</h2>
                    @unless ($revealed || ! $hasChatEvidence)
                        <x-ui.alert type="info">{{ __('Reveal the evidence before deciding.') }}</x-ui.alert>
                    @else
                        <x-ui.textarea :label="__('Note (optional; recorded in the audit log)')" name="note" id="caseNote" wire:model="note" error="note" rows="2" maxlength="500" />
                        <p class="form-text">{{ __('The members who reported are told only that their report was reviewed and closed.') }}</p>
                        <div class="admin-decisions mt-3">
                            @foreach ($outcomes as $outcome)
                                @if ($outcome->needsReason())
                                    <x-ui.button size="sm" variant="danger" type="button" x-on:click="$dispatch('open-modal', { name: 'decide-{{ strtolower($outcome->value) }}' })">{{ $outcome->label() }}</x-ui.button>
                                @else
                                    <x-ui.button size="sm" type="button" :variant="$outcome === \App\Enums\CaseOutcome::NoAction ? 'outline' : 'primary'"
                                                 wire:click="decide('{{ $outcome->value }}')" loading="decide">{{ $outcome->label() }}</x-ui.button>
                                @endif
                            @endforeach
                        </div>
                    @endunless
                </div>

                @foreach ($outcomes as $outcome)
                    @if ($outcome->needsReason())
                        <x-admin.confirm-modal :name="'decide-'.strtolower($outcome->value)" :title="$outcome->label()" action="decide('{{ $outcome->value }}')" reasonModel="note" :confirmLabel="$outcome->label()">
                            <p>{{ match ($outcome) {
                                \App\Enums\CaseOutcome::Hide => __('The profile leaves search and profile pages; the member can still sign in.'),
                                \App\Enums\CaseOutcome::Suspend => __('The member can no longer sign in and every session ends. Their profile leaves search.'),
                                default => __('The account is banned: sessions end, every conversation freezes, and the mobile number, email and photos go on the blocklist so the member can’t register again.'),
                            } }}</p>
                        </x-admin.confirm-modal>
                    @endif
                @endforeach
            @endif
        </div>
    </div>

    @if ($canTriage)
        <x-ui.modal name="case-priority" :title="__('Change priority')">
            <x-ui.select :label="__('Priority')" name="priority" id="casePrioritySelect" wire:model="priority" :options="$priorities" required />
            <div class="mt-3">
                <x-ui.textarea :label="__('Reason (recorded in the audit log)')" name="priorityReason" id="priorityReason" wire:model="priorityReason" error="reason" rows="2" required />
            </div>
            <x-slot:footer>
                <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" wire:click="changePriority" loading="changePriority">{{ __('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
