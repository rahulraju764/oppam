{{--
    /settings → Notifications (M14 / M08): one row per event, a column per channel. A fixed cell
    (mandatory event, or a channel the event never uses) is shown, not editable. Push is v1.5.
    Quiet hours are one window for everyone (A15), shown as information.
--}}
<div>
    <form wire:submit="save" id="notificationForm" novalidate>
        <div class="ui-card mb-4">
            <h2 class="safety-list__title">{{ __('What we tell you about') }}</h2>
            @unless ($emailReady)
                <p class="form-text">{{ __('Email: we only write to a verified email address. Your email choices apply once you add and verify one —') }}
                    <a href="{{ route('member.settings', ['tab' => 'account']) }}" wire:navigate>{{ __('verify my email') }}</a></p>
            @endunless
            @unless ($smsReady)
                <p class="form-text">{{ __('SMS: we only text a verified mobile number.') }}</p>
            @endunless

            <div class="nt-matrix-wrap">
                <table class="table nt-matrix">
                    <caption class="visually-hidden">{{ __('Notification channels for each kind of update') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Update') }}</th>
                            <th scope="col" class="text-center" id="nt-col-in_app">{{ __('On Oppam') }}</th>
                            <th scope="col" class="text-center" id="nt-col-email">{{ __('Email') }}</th>
                            <th scope="col" class="text-center" id="nt-col-sms">{{ __('SMS') }}</th>
                            <th scope="col" class="text-center">{{ __('Push') }} <span class="nt-matrix__soon">{{ __('soon') }}</span></th>
                        </tr>
                    </thead>
                    @foreach ($groups as $group => $events)
                        <tbody>
                            <tr class="nt-matrix__group"><th scope="rowgroup" colspan="5">{{ $group }}</th></tr>
                            @foreach ($events as $event)
                                @php($e = $event->value)
                                <tr wire:key="nt-{{ $e }}">
                                    <th scope="row" id="nt-{{ $e }}">
                                        {{ $event->label() }}
                                        @if ($event->isMandatory())
                                            <span class="nt-matrix__fixed">{{ __('always sent') }}</span>
                                        @endif
                                    </th>
                                    @foreach (['in_app' => __('On Oppam'), 'email' => __('Email'), 'sms' => __('SMS')] as $channel => $channelLabel)
                                        <td class="text-center">
                                            @if (! $event->usesChannel($channel))
                                                <span aria-hidden="true">—</span><span class="visually-hidden">{{ __(':channel not used for this update', ['channel' => $channelLabel]) }}</span>
                                            @else
                                                {{-- The label is the 44 px tap area; the name comes from the row and column headers. --}}
                                                <label class="nt-matrix__hit" for="nt-{{ $e }}-{{ $channel }}">
                                                    <input type="checkbox" class="form-check-input" id="nt-{{ $e }}-{{ $channel }}" name="matrix[{{ $e }}][{{ $channel }}]"
                                                           aria-labelledby="nt-{{ $e }} nt-col-{{ $channel }}" dusk="nt-{{ $e }}-{{ $channel }}"
                                                           @if ($event->isMandatory()) checked disabled @else wire:model="matrix.{{ $e }}.{{ $channel }}" @endif>
                                                </label>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-center"><span aria-hidden="true">—</span><span class="visually-hidden">{{ __('Push notifications are coming soon') }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        </div>

        <div class="ui-card mb-4">
            <h2 class="safety-list__title">{{ __('Quiet hours') }}</h2>
            <p class="form-text mb-0">{{ __('We don’t send SMS between :from and :until (India time) — they arrive when quiet hours end. Updates on Oppam and email are not held back.', ['from' => $quietFrom, 'until' => $quietUntil]) }}</p>
        </div>

        <div class="d-flex justify-content-end">
            <x-ui.button type="submit" variant="primary" loading="save" dusk="notifications-save">{{ __('Save notification settings') }}</x-ui.button>
        </div>
    </form>
</div>
