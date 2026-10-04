{{--
    /matches/daily — Daily Matches (M05 / F06, template daily-matches.php: heading band, results
    col-9, ad rail col-3). Today's batch with its expiry countdown; "Not interested" removes one.
--}}
<div>
    <section class="daily-section bg-coloring">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="d-head daily-head">
                        <h1>{{ __('Daily Matches') }}</h1>
                        @if ($cards !== [])
                            <p class="daily-head__expiry" x-data="countdown({{ $expiresIn }})">
                                <i class="fa fa-clock-o" aria-hidden="true"></i>
                                {{ __('Today’s matches expire in') }} <strong x-text="label">{{ gmdate('G\h i\m', $expiresIn) }}</strong>
                            </p>
                        @endif
                    </div>
                </div>

                <div class="col-lg-9 col-md-12 col-sm-12 col-12">
                    @if ($notice)
                        <x-ui.alert type="info">{{ $notice }}</x-ui.alert>
                    @endif

                    <div class="profile-grid">
                        @forelse ($cards as $card)
                            <x-profile.row :profile="$card" wire:key="daily-{{ $card->code }}">
                                <x-slot:actions>
                                    <x-ui.button type="button" variant="ghost" size="sm" icon="fa-times"
                                                 wire:click="dismiss('{{ $card->code }}')" loading="dismiss"
                                                 aria-label="{{ __('Not interested in :name', ['name' => $card->name]) }}">{{ __('Not interested') }}</x-ui.button>
                                </x-slot:actions>
                            </x-profile.row>
                        @empty
                            <x-ui.empty-state icon="fa-bolt" :title="__('No daily matches right now')"
                                              :message="__('New matches arrive every morning at 5 AM, picked from your partner preferences.')">
                                <x-ui.button :href="route('member.matches')" wire:navigate>{{ __('See all my matches') }}</x-ui.button>
                            </x-ui.empty-state>
                        @endforelse
                    </div>
                </div>

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>
</div>
