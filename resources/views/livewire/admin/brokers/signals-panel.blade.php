{{--
    A07 fraud & quality signals of one bureau (§11A B.12): open signals with their counts; resolve
    (brokers.edit) and void all commissions (brokers.payout). Each detail is a code or a count.
--}}
<div>
    @if ($signals->isNotEmpty())
        <div class="ui-card mb-4" role="region" aria-label="{{ __('Fraud and quality signals') }}">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <h2 class="h6 mb-2"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> {{ __('Signals to review (:n)', ['n' => $signals->count()]) }}</h2>
                @can('brokers.payout')
                    <x-ui.button size="sm" variant="danger" type="button" icon="fa-ban" wire:click="confirm('signal-void-all')" dusk="signal-void-all-open">{{ __('Void all commissions') }}</x-ui.button>
                @endcan
            </div>
            <ul class="list-unstyled mb-0">
                @foreach ($signals as $signal)
                    <li class="d-flex flex-wrap justify-content-between align-items-start gap-2 py-2" wire:key="sig-{{ $signal->id }}">
                        <div>
                            <x-ui.badge :variant="$signal->type->holdsPayouts() ? 'danger' : 'warning'">{{ $signal->type->label() }}</x-ui.badge>
                            <div class="small mt-1">
                                @foreach ($signal->details ?? [] as $key => $value)
                                    <span class="me-2">{{ \Illuminate\Support\Str::of((string) $key)->replace('_', ' ')->ucfirst() }}: <strong>{{ is_array($value) ? implode(', ', $value) : $value }}</strong></span>
                                @endforeach
                            </div>
                            <div class="small text-muted-brand">
                                {{ __('First seen :first · last seen :last', ['first' => $signal->detected_at->timezone($timezone)->format('d M Y'), 'last' => $signal->last_seen_at->timezone($timezone)->format('d M Y')]) }}
                                @if ($signal->type->holdsPayouts()) · {{ $payoutsHeld ? __('payouts held') : __('payout hold released') }} @endif
                            </div>
                        </div>
                        @can('brokers.edit')
                            <x-ui.button size="sm" variant="outline" type="button" icon="fa-check" wire:click="confirm('signal-resolve', '{{ $signal->id }}')">{{ __('Resolve') }}</x-ui.button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('brokers.edit')
        <x-admin.confirm-modal name="signal-resolve" :title="__('Resolve signal')" action="resolve" :confirmLabel="__('Resolve')" :danger="false">
            <p>{{ __('Say what you checked. A payout hold stays until you release it on the Overview.') }}</p>
        </x-admin.confirm-modal>
    @endcan
    @can('brokers.payout')
        <x-admin.confirm-modal name="signal-void-all" :title="__('Void all commissions of :code', ['code' => $code])" action="voidAll" :confirmLabel="__('Void all')">
            <p>{{ __('Every pending, earned and paid commission of this bureau becomes void. Paid ones are taken back in the next payout run. Referral history stays. Suspend the bureau separately if needed.') }}</p>
        </x-admin.confirm-modal>
    @endcan
</div>
