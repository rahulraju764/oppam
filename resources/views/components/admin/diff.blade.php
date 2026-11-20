{{--
    <x-admin.diff> — an audit row's before / after (PRD §11.0 admin kit, A12). lines: list of
    App\Data\Audit\AuditDiffLineData (already redacted). Changed lines carry a text marker, never
    colour alone. Values are escaped text.
--}}
@props(['lines' => []])

@if ($lines === [])
    <p class="text-muted-brand small m-0">{{ __('No before / after values were recorded for this entry.') }}</p>
@else
    <div class="admin-table-wrap">
        <table class="admin-table admin-diff">
            <caption class="visually-hidden">{{ __('Values before and after') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('Field') }}</th>
                    <th scope="col">{{ __('Before') }}</th>
                    <th scope="col">{{ __('After') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr @class(['admin-diff__changed' => $line->changed])>
                        <th scope="row">
                            <code>{{ $line->key }}</code>
                            @if ($line->changed)
                                <span class="visually-hidden">{{ __('(changed)') }}</span>
                                <x-ui.badge variant="warning">{{ __('Changed') }}</x-ui.badge>
                            @endif
                        </th>
                        <td class="admin-diff__value">{{ $line->before === '' ? '—' : $line->before }}</td>
                        <td class="admin-diff__value">{{ $line->after === '' ? '—' : $line->after }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
