{{--
    /visitors (M15): two tabs — "Who viewed me" (cards with the last visit for plans with
    see_who_viewed_me; otherwise the number, a data-free blurred teaser and an upgrade link) and
    "Profiles I viewed" (all plans). 90 days, distinct members, 20 per page. 3/6/3-style row:
    results col-9, ad rail col-3, like Daily Matches.
--}}
<div>
    <section class="daily-section bg-coloring">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="d-head daily-head">
                        <h1>{{ __('Visitors') }}</h1>
                        <p class="daily-head__expiry">{{ __('Activity from the last 90 days') }}</p>
                    </div>
                </div>

                <div class="col-lg-9 col-md-12 col-sm-12 col-12">
                    <div class="visitor-tabs" role="group" aria-label="{{ __('Visitors') }}">
                        <button type="button" class="visitor-tab" wire:click="show('visitors')" aria-pressed="{{ $tab === 'visitors' ? 'true' : 'false' }}">
                            {{ __('Who viewed me') }} <span class="nav-count">{{ number_format($visitorCount) }}</span>
                        </button>
                        <button type="button" class="visitor-tab" wire:click="show('viewed')" aria-pressed="{{ $tab === 'viewed' ? 'true' : 'false' }}">
                            {{ __('Profiles I viewed') }} <span class="nav-count">{{ number_format($viewedCount) }}</span>
                        </button>
                    </div>

                    <div wire:loading.flex wire:target="show" class="search-loading" aria-hidden="true">
                        <x-ui.skeleton shape="row" :count="3" class="w-100" />
                    </div>

                    <div wire:loading.remove wire:target="show">
                        @if ($locked && $visitorCount === 0)
                            <x-ui.empty-state icon="fa-eye" :title="__('No visitors yet')" :message="__('A complete profile with photos gets more visits.')" />
                        @elseif ($locked)
                            <div class="dashboard-content">
                                <section class="home-content">
                                    <div class="visitor-teaser">
                                        <div class="visitor-teaser__blur" aria-hidden="true">
                                            @for ($i = 0; $i < 3; $i++)<span class="visitor-teaser__tile"></span>@endfor
                                        </div>
                                        <div class="visitor-teaser__body">
                                            <p class="visitor-teaser__count">{{ trans_choice(':count member viewed your profile|:count members viewed your profile', $visitorCount, ['count' => number_format($visitorCount)]) }}</p>
                                            <p class="mb-0">{{ __('Upgrade to Gold or Diamond to see who they are and when they visited.') }}</p>
                                            <x-ui.button :href="route('plans')" wire:navigate>{{ __('See plans') }}</x-ui.button>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        @else
                            <div class="profile-grid">
                                @forelse ($rows as $row)
                                    <x-profile.row :profile="$row['card']" wire:key="visit-{{ $tab }}-{{ $row['card']->code }}">
                                        <x-slot:actions>
                                            <span class="visit-time"><i class="fa fa-clock-o" aria-hidden="true"></i>
                                                {{ $tab === 'viewed' ? __('You viewed :when', ['when' => $row['when']]) : __('Viewed you :when', ['when' => $row['when']]) }}</span>
                                        </x-slot:actions>
                                    </x-profile.row>
                                @empty
                                    <x-ui.empty-state icon="fa-eye" :title="$tab === 'viewed' ? __('You haven’t viewed any profiles yet') : __('No visitors yet')"
                                                      :message="$tab === 'viewed' ? __('Profiles you open appear here for 90 days.') : __('A complete profile with photos gets more visits.')">
                                        <x-ui.button :href="route('member.matches')" wire:navigate>{{ __('See my matches') }}</x-ui.button>
                                    </x-ui.empty-state>
                                @endforelse
                            </div>
                            @if ($page)
                                <x-ui.pagination :paginator="$page" :label="__('Visitors pages')" />
                            @endif
                        @endif
                    </div>
                </div>

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>
</div>
