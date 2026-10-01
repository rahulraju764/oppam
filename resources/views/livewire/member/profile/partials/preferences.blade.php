{{-- Partner Preference tab: what they look for, and "You match X of Y" (M03). --}}
@php($matched = count(array_filter($checks, fn ($check) => $check->matches)))

@if ($profile->partnerPreference?->about_partner)
    <div class="dtl-basic-info is-wide">
        <div class="basic-info-hd"><h4>{{ __('What :name is looking for', ['name' => $name]) }}</h4></div>
        <p>{{ $profile->partnerPreference->about_partner }}</p>
    </div>
@endif

@if ($checks === [])
    <x-ui.empty-state icon="fa-sliders" :title="__('No specific preferences')" :message="__(':name is open to all matches.', ['name' => $name])" />
@else
    <div class="dtl-basic-info is-wide">
        <div class="basic-info-hd">
            <h4>{{ __('You match :matched of :total preferences', ['matched' => $matched, 'total' => count($checks)]) }}</h4>
        </div>
        <table class="table tb-basic-info preference-checks">
            @foreach ($checks as $check)
                <tr>
                    <td class="dtl-profile">{{ $check->label }}</td>
                    <td class="dtls">{{ $check->wanted }}</td>
                    <td class="dtls text-end">
                        @if ($check->matches)
                            <i class="fa fa-check-circle is-match" aria-hidden="true"></i><span class="visually-hidden">{{ __('You match') }}</span>
                        @else
                            <i class="fa fa-times-circle is-miss" aria-hidden="true"></i><span class="visually-hidden">{{ __('You don’t match') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
