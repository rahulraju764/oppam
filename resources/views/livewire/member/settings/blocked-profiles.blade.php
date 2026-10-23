{{--
    /settings/blocked (PRD M09): the member's own blocks and ignores. Blocked members show code and
    first name only — no photo or link (a blocked pair never sees each other's profile).
--}}
<div>
    <section class="daily-section bg-coloring">
        <div class="container is-readable">
            <div class="d-head daily-head">
                <h1>{{ __('Blocked & ignored') }}</h1>
                <p class="daily-head__expiry">{{ __('Only you can see this list. Nobody is told when you block, ignore or undo it.') }}</p>
            </div>

            @if (session('status'))
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            @endif

            <div class="ui-card mb-4">
                <h2 class="safety-list__title">{{ __('Blocked') }}</h2>
                <p class="form-text">{{ __('You and these members can’t see each other anywhere on Oppam.') }}</p>
                @if ($blocked->isEmpty())
                    <x-ui.empty-state icon="fa-ban" :title="__('You haven’t blocked anyone')" :message="__('Use More → Block on a profile if someone makes you uncomfortable.')" />
                @else
                    <ul class="safety-list">
                        @foreach ($blocked as $row)
                            <li class="safety-list__row" wire:key="blocked-{{ $row->blocked?->code }}">
                                <span class="safety-list__who">
                                    <strong>{{ $row->blocked?->first_name ?? __('Member') }}</strong>
                                    <span class="text-muted-brand">{{ $row->blocked?->code }} · {{ __('blocked :date', ['date' => $row->created_at?->timezone($timezone)->format('d M Y')]) }}</span>
                                </span>
                                @if ($row->blocked)
                                    <span class="d-flex flex-wrap gap-2">
                                        <x-ui.button size="sm" variant="ghost" type="button" icon="fa-flag" wire:click="openReport('{{ $row->blocked->code }}')"
                                                     loading="openReport('{{ $row->blocked->code }}')">{{ __('Report') }}<span class="visually-hidden"> {{ $row->blocked->first_name }}</span></x-ui.button>
                                        <x-ui.button size="sm" variant="outline" type="button" wire:click="unblock('{{ $row->blocked->code }}')"
                                                     loading="unblock('{{ $row->blocked->code }}')">{{ __('Unblock') }}<span class="visually-hidden"> {{ $row->blocked->first_name }}</span></x-ui.button>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="ui-card">
                <h2 class="safety-list__title">{{ __('Ignored') }}</h2>
                <p class="form-text">{{ __('These members don’t appear in your search and lists. Nothing changes for them.') }}</p>
                @if ($ignored->isEmpty())
                    <x-ui.empty-state icon="fa-eye-slash" :title="__('You haven’t ignored anyone')" :message="__('Use More → Ignore on a profile to stop seeing it.')" />
                @else
                    <ul class="safety-list">
                        @foreach ($ignored as $row)
                            <li class="safety-list__row" wire:key="ignored-{{ $row->code }}">
                                <span class="safety-list__who">
                                    <strong>{{ $row->first_name }}</strong>
                                    <span class="text-muted-brand">{{ $row->code }} · {{ __('ignored :date', ['date' => $row->created_at?->timezone($timezone)->format('d M Y')]) }}</span>
                                </span>
                                <x-ui.button size="sm" variant="outline" type="button" wire:click="unignore('{{ $row->code }}')" loading="unignore('{{ $row->code }}')">{{ __('Show again') }}<span class="visually-hidden"> {{ $row->first_name }}</span></x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- "Block, then report": the safety team sees the report; the member is never told. --}}
            <x-ui.modal name="report-blocked" :title="__('Report :code', ['code' => $reportCode])">
                <form wire:submit="report" id="reportBlockedForm" novalidate>
                    <x-ui.select :label="__('What is wrong?')" name="reportReason" id="blockedReportReason" wire:model="reportReason"
                                 :options="$reasons" :placeholder="__('Choose a reason')" error="reason" required />
                    <div class="mt-3">
                        <x-ui.textarea :label="__('Anything else we should know? (needed for “something else”)')" name="reportDetails" id="blockedReportDetails"
                                       wire:model="reportDetails" error="details" rows="3" maxlength="500" />
                    </div>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="danger" type="submit" form="reportBlockedForm" loading="report">{{ __('Send report') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>

            <p class="mt-4"><a href="{{ route('safety') }}" wire:navigate><i class="fa fa-shield" aria-hidden="true"></i> {{ __('Safety Centre — tips and how to report') }}</a></p>
        </div>
    </section>
</div>
