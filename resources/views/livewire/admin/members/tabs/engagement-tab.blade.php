{{--
    A03 Engagement tab: counts, then the member's interests in either direction (other member's
    code, direction, status, when). Favourites are a number only (R-M06-6); no notes anywhere.
--}}
<div>
    @unless ($hasProfile)
        <x-ui.card><x-ui.empty-state icon="fa-heart-o" :title="__('No profile yet')" :message="__('This account has not started a profile.')" /></x-ui.card>
    @else
        <div class="row g-3 mb-3">
            @foreach ([
                'likesGiven' => __('Likes given'), 'likesReceived' => __('Likes received'),
                'interestsSent' => __('Interests sent'), 'interestsReceived' => __('Interests received'),
                'pendingReceived' => __('Interests awaiting this member'), 'connections' => __('Connections'),
                'requestsWaiting' => __('Photo / horoscope requests waiting'), 'favorites' => __('Favourites'),
            ] as $key => $label)
                <div class="col-6 col-md-3" wire:key="eng-{{ $key }}">
                    <x-admin.stat-card :label="$label" :value="number_format($counts[$key])"
                                       :hint="$key === 'favorites' ? __('A number only — favourites are private') : null" />
                </div>
            @endforeach
        </div>

        <x-admin.table :headers="[__('When (IST)'), __('Direction'), __('Other member'), __('Status'), __('Answered')]" :caption="__('Interests')" :empty="$interests->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-envelope-o" :title="__('No interests yet')" />
            </x-slot:emptyState>

            @foreach ($interests as $interest)
                <tr wire:key="eng-interest-{{ $interest->id }}">
                    <td>{{ $interest->sent_at->timezone($timezone)->format('d M Y, g:i a') }}</td>
                    <td>{{ $interest->from_profile_id === $profileId ? __('Sent') : __('Received') }}</td>
                    <td><a href="{{ route('admin.members.show', ['profile' => $interest->other_code]) }}">{{ $interest->other_code }}</a></td>
                    <td><x-ui.badge :variant="$interest->status->badge()">{{ $interest->status->label() }}</x-ui.badge></td>
                    <td>{{ $interest->responded_at?->timezone($timezone)->format('d M Y, g:i a') ?? '—' }}</td>
                </tr>
            @endforeach
        </x-admin.table>
        <div class="mt-3">{{ $interests->links() }}</div>
    @endunless
</div>
