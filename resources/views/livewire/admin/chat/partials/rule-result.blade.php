{{-- The A13 dry-run result (Rules: the page's tester and the edit dialog's draft). Sample text only — never a member's message. --}}
@if ($result !== null)
    <div class="admin-rule-result mt-3" aria-live="polite">
        @if ($result['blocked'])
            <x-ui.alert type="danger">{{ __('Blocked: the member would be told “This message can’t be sent: :reason.”', ['reason' => $result['blocked']]) }}</x-ui.alert>
        @else
            <p class="mb-1"><strong>{{ __('The recipient would see:') }}</strong></p>
            <p class="admin-rule-result__text">{{ $result['text'] }}</p>
            <p class="small mb-2">
                {{ $result['flags'] === [] ? __('Not flagged.') : __('Flagged for: :reasons', ['reasons' => implode(', ', $result['flags'])]) }}
            </p>
        @endif
        @if ($result['hits'] !== [])
            <ul class="small mb-0">
                @foreach ($result['hits'] as $hit)
                    <li>{{ $hit['rule'] }} — {{ $hit['action'] }}@if ($hit['matches'] !== ''): <code>{{ $hit['matches'] }}</code>@endif</li>
                @endforeach
            </ul>
        @else
            <p class="small mb-0">{{ __('No rule matched.') }}</p>
        @endif
    </div>
@endif
