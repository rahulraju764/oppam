{{--
    A13 abuse signals (PRD A13 "Engagement abuse monitors"). Counts and member codes only — never
    message text. One-click actions open a dialog; the server re-checks every permission.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Abuse signals') }}</h1>
            <p>{{ __('Members whose likes, interests, favourites or messages look automated or abusive. Pause, warn, suspend or dismiss.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3">
            <div class="col-md-6">
                <x-ui.select :label="__('Show')" name="view" id="signalView" wire:model.live="view" :options="$views" />
            </div>
            @if ($view !== 'paused')
                <div class="col-md-6">
                    <x-ui.select :label="__('Kind')" name="type" id="signalType" wire:model.live="type" :options="$types" :placeholder="__('All kinds')" />
                </div>
            @endif
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="view, type, gotoPage, nextPage, previousPage">
        @if ($view === 'paused')
            <x-admin.table :headers="[__('Member'), __('Paused until (IST)'), __('Reason'), __('By'), '']" :caption="__('Members whose sending is paused')" :empty="$this->pauses->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-pause-circle" :title="__('Nobody is paused')" :message="__('Pauses you set on a signal show here until they end.')" />
                </x-slot:emptyState>

                @foreach ($this->pauses as $pause)
                    <tr wire:key="pause-{{ $pause->id }}">
                        <td>
                            @if ($canViewMembers && $pause->profile)
                                <a href="{{ route('admin.members.show', ['profile' => $pause->profile->code]) }}" wire:navigate>{{ $pause->profile->code }}</a>
                            @else
                                {{ $pause->profile?->code ?? '—' }}
                            @endif
                        </td>
                        <td>{{ $pause->until->setTimezone(config('oppam.display_timezone'))->format('d M Y, H:i') }}</td>
                        <td>{{ $pause->reason ?? '—' }}</td>
                        <td>{{ $pause->createdBy?->name ?? '—' }}</td>
                        <td class="text-end">
                            @if ($canLift)
                                <x-ui.button size="sm" variant="outline" type="button" wire:click="choose('{{ $pause->id }}', 'lift-pause')">{{ __('Lift pause') }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            {{ $this->pauses->links() }}
        @else
            <x-admin.table :headers="[__('Kind'), __('Member'), __('What happened'), __('Raised'), __('Status'), '']" :caption="__('Abuse signals')" :empty="$this->signals->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-bolt" :title="__('Nothing here')" :message="$view === 'waiting' ? __('No abuse signals are waiting.') : __('No signals match these filters.')" />
                </x-slot:emptyState>

                @foreach ($this->signals as $signal)
                    <tr wire:key="signal-{{ $signal->id }}">
                        <td><x-ui.badge variant="warning" icon="fa-bolt">{{ $signal->type->label() }}</x-ui.badge></td>
                        <td>
                            @if ($canViewMembers && $signal->profile)
                                <a href="{{ route('admin.members.show', ['profile' => $signal->profile->code]) }}" wire:navigate>{{ $signal->profile->code }}</a>
                            @else
                                {{ $signal->profile?->code ?? '—' }}
                            @endif
                        </td>
                        <td>
                            {{ $signal->type->describe($signal->details) }}
                            @if ($signal->occurrences > 1)
                                <span class="d-block text-muted small">{{ trans_choice('Seen :count time|Seen :count times', $signal->occurrences, ['count' => $signal->occurrences]) }}</span>
                            @endif
                        </td>
                        <td>{{ ($signal->last_raised_at ?? $signal->created_at)?->setTimezone(config('oppam.display_timezone'))->format('d M Y, H:i') }}</td>
                        <td>
                            {{ $signal->status->label() }}
                            @if ($signal->decision)
                                <span class="d-block text-muted small">{{ $signal->decision->label() }}@if ($signal->decidedBy) · {{ $signal->decidedBy->name }}@endif</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($signal->status === \App\Enums\EngagementSignalStatus::Open)
                                <div class="admin-decisions justify-content-end">
                                    @foreach ($decisions as $decision)
                                        <x-ui.button size="sm" type="button"
                                                     :variant="$decision === \App\Enums\EngagementSignalDecision::Suspend ? 'danger' : ($decision === \App\Enums\EngagementSignalDecision::Dismiss ? 'outline' : 'primary')"
                                                     wire:click="choose('{{ $signal->id }}', 'signal-{{ strtolower($decision->value) }}')">{{ $decision->label() }}</x-ui.button>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            {{ $this->signals->links() }}
        @endif
    </div>

    @foreach ($decisions as $decision)
        @php($dialog = 'signal-'.strtolower($decision->value))
        <x-ui.modal :name="$dialog" :title="$decision->label()">
            @switch($decision)
                @case(\App\Enums\EngagementSignalDecision::RateLimit)
                    <p>{{ __('Likes, favourites, interests, photo / horoscope requests and chat messages from this member are refused until the pause ends. The member is told why when they try.') }}</p>
                    <x-ui.select :label="__('Pause for')" name="hours" id="signalHours" wire:model="hours" :options="$hourOptions" />
                    @break
                @case(\App\Enums\EngagementSignalDecision::Warn)
                    <p>{{ __('The member gets a fixed warning in the app and by email. It doesn’t say what was detected.') }}</p>
                    @break
                @case(\App\Enums\EngagementSignalDecision::Suspend)
                    <p>{{ __('The member can no longer sign in and every session ends. Their profile leaves search.') }}</p>
                    @break
                @default
                    <p>{{ __('Nothing happens to the member. The signal moves to Decided.') }}</p>
            @endswitch

            <div class="mt-3">
                <x-ui.textarea :label="$decision === \App\Enums\EngagementSignalDecision::Suspend ? __('Reason (recorded in the audit log)') : __('Note (optional; recorded in the audit log)')"
                               name="{{ $dialog }}-note" id="{{ $dialog }}-note" wire:model="note" error="note" rows="2"
                               :required="$decision === \App\Enums\EngagementSignalDecision::Suspend" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button :variant="$decision === \App\Enums\EngagementSignalDecision::Suspend ? 'danger' : 'primary'" type="button"
                             wire:click="decide('{{ $decision->value }}')" loading="decide">{{ $decision->label() }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endforeach

    @if ($canLift)
        <x-admin.confirm-modal name="lift-pause" :title="__('Lift the pause')" action="lift" reasonModel="note" :danger="false" :confirmLabel="__('Lift pause')">
            <p>{{ __('The member can send likes, interests and messages again straight away.') }}</p>
        </x-admin.confirm-modal>
    @endif
</div>
