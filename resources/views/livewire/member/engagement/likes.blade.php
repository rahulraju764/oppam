{{--
    /likes (PRD M06, M15): "Liked me" (cards for plans with see_who_liked_me; otherwise the number,
    a data-free blurred teaser and an upgrade link), "I liked" and "Mutual" (all plans). 20 per
    page, newest first; unseen likes carry a "New" badge for this visit. Same frame as /visitors:
    results col-9, ad rail col-3. Each row brings its own Like / Favorite buttons (x-profile.row).
--}}
@php($labels = ['liked-me' => __('Liked me'), 'liked' => __('I liked'), 'mutual' => __('Mutual')])
<div>
    <section class="daily-section bg-coloring">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="d-head daily-head">
                        <h1>{{ __('Likes') }}</h1>
                        <p class="daily-head__expiry">{{ __('Like someone back to make it a match') }}</p>
                    </div>
                </div>

                <div class="col-lg-9 col-md-12 col-sm-12 col-12">
                    <div class="visitor-tabs" role="group" aria-label="{{ __('Likes') }}">
                        @foreach ($labels as $key => $label)
                            <button type="button" class="visitor-tab" wire:click="show('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">
                                {{ $label }} <span class="nav-count">{{ number_format($counts[$key]) }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div wire:loading.flex wire:target="show" class="search-loading" aria-hidden="true">
                        <x-ui.skeleton shape="row" :count="3" class="w-100" />
                    </div>

                    <div wire:loading.remove wire:target="show">
                        @if ($locked && $counts['liked-me'] === 0)
                            <x-ui.empty-state icon="fa-heart-o" :title="__('No likes yet')" :message="__('A complete profile with photos gets more likes.')">
                                <x-ui.button :href="route('member.profile.me')" wire:navigate>{{ __('Improve my profile') }}</x-ui.button>
                            </x-ui.empty-state>
                        @elseif ($locked)
                            <div class="dashboard-content">
                                <section class="home-content">
                                    <div class="visitor-teaser">
                                        <div class="visitor-teaser__blur" aria-hidden="true">
                                            @for ($i = 0; $i < 3; $i++)<span class="visitor-teaser__tile"></span>@endfor
                                        </div>
                                        <div class="visitor-teaser__body">
                                            <p class="visitor-teaser__count">{{ trans_choice(':count member liked your profile|:count members liked your profile', $counts['liked-me'], ['count' => number_format($counts['liked-me'])]) }}</p>
                                            <p class="mb-0">{{ __('Upgrade to see who they are and like them back.') }}</p>
                                            <x-ui.button :href="route('plans')" wire:navigate>{{ __('See plans') }}</x-ui.button>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        @else
                            <div class="profile-grid">
                                @forelse ($rows as $row)
                                    <x-profile.row :profile="$row['card']" wire:key="like-{{ $tab }}-{{ $row['card']->code }}">
                                        <x-slot:actions>
                                            <span class="visit-time">
                                                @if ($row['new'])<x-ui.badge variant="premium">{{ __('New') }}</x-ui.badge>@endif
                                                <i class="fa fa-clock-o" aria-hidden="true"></i>
                                                {{ $tab === 'liked' ? __('You liked :when', ['when' => $row['when']]) : __('Liked you :when', ['when' => $row['when']]) }}
                                            </span>
                                        </x-slot:actions>
                                    </x-profile.row>
                                @empty
                                    @php($empty = match ($tab) {
                                        'liked' => [__('You haven’t liked anyone yet'), __('Tap Like on a profile you’re interested in — they’ll be told.')],
                                        'mutual' => [__('No mutual likes yet'), __('When someone you liked likes you back, they appear here.')],
                                        default => [__('No likes yet'), __('A complete profile with photos gets more likes.')],
                                    })
                                    <x-ui.empty-state icon="fa-heart-o" :title="$empty[0]" :message="$empty[1]">
                                        <x-ui.button :href="route('member.matches')" wire:navigate>{{ __('See my matches') }}</x-ui.button>
                                    </x-ui.empty-state>
                                @endforelse
                            </div>
                            @if ($page)
                                <x-ui.pagination :paginator="$page" :label="__('Likes pages')" />
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
