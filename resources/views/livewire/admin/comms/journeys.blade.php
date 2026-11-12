{{--
    A14 automated journeys (P8.3b2): on / off, day offsets, one channel per journey, and what each
    did in the last 30 days. The copy is the journey event's A14 template. Plan-expiry reminders are
    built in (P5.2) and shown read-only.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Automated journeys') }}</h1>
            <p>{{ __('Reminders sent once a day at 10:30 IST. A member gets at most one journey message a day, only on channels they haven’t switched off, and never an SMS at night.') }}</p>
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="edit, save">
        <x-admin.table :headers="[__('Journey'), __('Sends on'), __('Channel'), __('Last :n days', ['n' => \App\Livewire\Admin\Comms\Journeys::STATS_DAYS]), __('Status'), '']"
                       :caption="__('Automated journeys')" :empty="$journeys->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-road" :title="__('No journeys set up')" :message="__('Run the database migrations to add the four journeys.')" />
            </x-slot:emptyState>

            @foreach ($keys as $key)
                @php($j = $journeys->get($key->value))
                @continue($j === null)
                @php($s = $stats->get($key->value, collect()))
                <tr wire:key="journey-{{ $key->value }}">
                    <td>
                        <strong>{{ $key->label() }}</strong>
                        <div class="small text-muted-brand">{{ $key->description() }}</div>
                    </td>
                    <td>
                        {{ implode(', ', $j->offsets) }}
                        <div class="small text-muted-brand">{{ $key->offsetMeaning() }}</div>
                    </td>
                    <td>{{ $j->channel->label() }}</td>
                    <td class="small">
                        {{ __('Sent :n', ['n' => number_format($s->get($sent, 0))]) }}<br>
                        {{ __('Skipped :n', ['n' => number_format($s->except($sent)->sum())]) }}
                    </td>
                    <td><x-ui.badge :variant="$j->is_active ? 'success' : 'muted'">{{ $j->is_active ? __('On') : __('Off') }}</x-ui.badge></td>
                    <td class="text-end">
                        @if ($canEdit)
                            <x-ui.button type="button" variant="secondary" size="sm" wire:click="edit('{{ $key->value }}')" loading="edit" dusk="journey-edit-{{ $key->value }}">
                                {{ __('Change') }}
                            </x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach

            <tr>
                <td>
                    <strong>{{ __('Plan expiry reminders') }}</strong>
                    <div class="small text-muted-brand">{{ __('Built in: 7 days and 1 day before a paid plan ends, 10:00 IST.') }}</div>
                </td>
                <td>7, 1 <div class="small text-muted-brand">{{ __('days before the plan ends') }}</div></td>
                <td>{{ __('Member’s preferences') }}</td>
                <td class="small">—</td>
                <td><x-ui.badge variant="default">{{ __('Built in') }}</x-ui.badge></td>
                <td class="text-end">
                    @can('templates.edit')
                        <a href="{{ route('admin.comms.templates') }}" wire:navigate class="small">{{ __('Edit its copy') }}</a>
                    @endcan
                </td>
            </tr>
        </x-admin.table>
    </div>

    <p class="small text-muted-brand mt-2">{{ __('The words of each journey message are edited in Templates, under the journey’s notification.') }}</p>

    @if ($canEdit)
        <x-admin.confirm-modal name="journey-edit" :title="$editingKey ? __('Change: :name', ['name' => $editingKey->label()]) : __('Change journey')"
                               action="save" :confirm-label="__('Save journey')" :danger="false">
            @if ($editingKey)
                <x-ui.checkbox name="journeyActive" id="journey-active" wire:model="isActive">{{ __('Send this journey') }}</x-ui.checkbox>

                <fieldset class="mt-3">
                    <legend class="mat-label">{{ __('Send on day (:meaning)', ['meaning' => $editingKey->offsetMeaning()]) }}</legend>
                    <div class="row g-2">
                        @foreach (range(0, \App\Actions\Admin\Comms\SaveJourney::MAX_OFFSETS - 1) as $i)
                            <div class="col-4">
                                <x-ui.input type="number" :label="__('Day :n', ['n' => $i + 1])" name="journeyOffset{{ $i }}" id="journey-offset-{{ $i }}"
                                            wire:model="offsets.{{ $i }}" min="1" max="365" inputmode="numeric" />
                            </div>
                        @endforeach
                    </div>
                    @error('offsets')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @foreach ($errors->get('offsets.*') as $messages)
                        <div class="invalid-feedback d-block">{{ $messages[0] }}</div>
                    @endforeach
                </fieldset>

                <div class="mt-3">
                    <x-ui.select :label="__('Channel')" name="journeyChannel" id="journey-channel" wire:model="channel"
                                 :options="$channelOptions" :selected="$channel" required />
                    @if ($channel === 'sms')
                        <p class="small text-muted-brand mt-1">{{ __('Only members who switched SMS on for this reminder get it, and only outside quiet hours. Needs a DLT template in Templates.') }}</p>
                    @endif
                </div>
            @endif
        </x-admin.confirm-modal>
    @endif
</div>
