{{--
    /dashboard (M05, template dashboard.php: 3/6/3 row). Left: own profile card, upgrade box for
    Free members, member menu. Centre: the sliders, each a lazy <livewire:member.dashboard.strip>
    (skeleton first). Right: the ad rail. The template's "VishwakarmaMatrimony" line is gone.
--}}
<div>
    <section class="dashboard-section">
        <div class="container">
            <div class="row dashboard-row">

                {{-- Left rail --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <div class="dashboard-sidebar">
                        <div class="sidebar-profile">
                            <a href="{{ route('member.profile.me') }}" wire:navigate>
                                <img src="{{ $photoUrl }}" alt="{{ __('Your profile photo') }}" width="600" height="600" loading="lazy" decoding="async">
                            </a>
                            <h1 class="h3">{{ $profile->fullName() }}</h1>
                            <p class="h4">{{ $profile->code }}</p>
                            <span>{{ $planLabel }}</span>
                            <div class="completeness">
                                <label class="completeness__label" for="completeness">
                                    <span>{{ __('Profile completeness') }}</span><strong>{{ $profile->completeness }}%</strong>
                                </label>
                                <progress class="completeness__bar" id="completeness" max="100" value="{{ $profile->completeness }}">{{ $profile->completeness }}%</progress>
                            </div>
                        </div>

                        @if ($isFree)
                            <div class="membership-box">
                                <p>{{ __('Upgrade membership to call/chat with matches') }}</p>
                                <a href="{{ route('plans') }}" class="upgrade-btn" wire:navigate>{{ __('Upgrade Now') }}</a>
                            </div>
                        @endif

                        <nav class="sidebar-menu" aria-label="{{ __('Member menu') }}">
                            @foreach ($menu as $item)
                                <a href="{{ route($item[0], $item[3] ?? []) }}{{ isset($item[4]) ? '#'.$item[4] : '' }}" wire:navigate>
                                    <i class="fa {{ $item[2] }}" aria-hidden="true"></i> {{ $item[1] }}
                                </a>
                            @endforeach
                        </nav>
                    </div>
                </div>

                {{-- Centre: the sliders --}}
                <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                    @foreach ($strips as $kind)
                        <livewire:member.dashboard.strip :kind="$kind" :key="'strip-'.$kind" />
                    @endforeach
                </div>

                {{-- Ad rail --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>
</div>
