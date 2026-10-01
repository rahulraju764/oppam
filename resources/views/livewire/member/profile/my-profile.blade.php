{{-- /me — own profile (M03, template my-profile.php: .single-detail.is-self header, completeness,
     About, detail sections as .info-grid, ad rail). --}}
<div>
    <section class="single-profile">
        <div class="container">
            @if (session('status'))
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            @endif

            @switch($profile->status)
                @case(\App\Enums\ProfileStatus::PendingReview)
                    <x-ui.alert type="info">{{ __('Your profile is under review. It will be visible to others once approved — usually within 24 hours.') }}</x-ui.alert>
                    @break
                @case(\App\Enums\ProfileStatus::Rejected)
                    <x-ui.alert type="warning">
                        <strong>{{ __('Your profile needs changes before it can go live.') }}</strong>
                        @if ($rejectionNote)<span class="d-block">{{ __('Reviewer’s note: :note', ['note' => $rejectionNote]) }}</span>@endif
                        <a href="{{ route('member.onboarding', ['step' => 1]) }}" wire:navigate>{{ __('Edit & resubmit') }}</a>
                    </x-ui.alert>
                    @break
                @case(\App\Enums\ProfileStatus::Hidden)
                    <x-ui.alert type="info">{{ __('Your profile is hidden. Other members can’t find or open it.') }}</x-ui.alert>
                    @break
                @case(\App\Enums\ProfileStatus::Suspended)
                    <x-ui.alert type="danger">{{ __('Your profile has been suspended. Please contact support.') }}</x-ui.alert>
                    @break
            @endswitch

            @if ($pendingText !== [])
                <x-ui.alert type="info">{{ __('Some of your text changes are waiting for a quick review; others still see the previous version.') }}</x-ui.alert>
            @endif

            <div class="row">
                <div class="col-lg-9 col-md-12 col-sm-12 col-12">

                    <div class="single-detail is-self">
                        <div class="profile-img">
                            <img src="{{ $photo?->cardUrl ?? \App\Domain\Media\PhotoUrls::PLACEHOLDER }}" class="img-fluid" alt="" width="600" height="600" fetchpriority="high">
                        </div>
                        <div class="profile-tittle">
                            <h1 class="pf-tt">{{ $profile->fullName() }}</h1>
                            <p class="pf-dt-n">{{ $profile->code }}<span>{{ $profile->status->label() }}</span></p>

                            <div class="profile-list">
                                <ul class="list">
                                    @isset($headline['age'])<li class="age">{{ $headline['age'] }}@isset($headline['height'])<span>{{ $headline['height'] }}</span>@endisset</li>@endisset
                                    @isset($headline['education'])<li class="study">{{ $headline['education'] }}@isset($headline['occupation'])<span>, {{ $headline['occupation'] }}</span>@endisset</li>@endisset
                                    @isset($headline['place'])<li class="place">{{ $headline['place'] }}</li>@endisset
                                </ul>

                                <div class="intst-parent">
                                    <div class="intst-button">
                                        @if ($canEdit)
                                            <a class="send-int" href="{{ route('member.onboarding', ['step' => 1]) }}" wire:navigate><i class="fa fa-pencil" aria-hidden="true"></i>{{ __('Edit Profile') }}</a>
                                            <a class="call-btn" href="{{ route('member.onboarding', ['step' => 6]) }}" wire:navigate><i class="fa fa-camera" aria-hidden="true"></i>{{ __('Manage Photos') }}</a>
                                        @endif
                                        @if ($profile->status === \App\Enums\ProfileStatus::Active)
                                            <a class="call-btn" href="{{ route('member.profile.show', ['profile' => $profile->code, 'preview' => 1]) }}" wire:navigate><i class="fa fa-eye" aria-hidden="true"></i>{{ __('Preview') }}</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        <span class="chip self-tier">{{ $plan->name }}</span>
                    </div>

                    <div class="information-table profile-progress">
                        <div class="info-block-head">
                            <h2>{{ __('Profile Completeness') }}</h2>
                            <span class="progress-value">{{ $percent }}%</span>
                        </div>
                        <progress class="progress-bar-native" max="100" value="{{ $percent }}">{{ $percent }}%</progress>

                        @if ($missing !== [])
                            <p class="text-muted-brand progress-note">{{ __('Complete profiles get up to 3× more responses.') }}</p>
                            <p class="progress-links">
                                @foreach ($missing as $part)
                                    @if ($canEdit)
                                        <a href="{{ route('member.onboarding', ['step' => $part['step']->value]) }}" wire:navigate>{{ __('Add :what (+:points%)', ['what' => $part['label'], 'points' => $part['points']]) }}</a>
                                    @else
                                        <span>{{ __('Add :what (+:points%)', ['what' => $part['label'], 'points' => $part['points']]) }}</span>
                                    @endif
                                @endforeach
                            </p>
                        @else
                            <p class="text-muted-brand progress-note">{{ __('Your profile is complete.') }}</p>
                        @endif
                    </div>

                    <div class="information-table">
                        <div class="info-block-head">
                            <h2><i class="fa fa-line-chart" aria-hidden="true"></i> {{ __('This Week') }}</h2>
                        </div>
                        <dl class="info-grid">
                            <div class="info-pair"><dt>{{ __('Profile views') }}</dt><dd>{{ $viewsThisWeek }}</dd></div>
                        </dl>
                    </div>

                    <div class="information-table">
                        <div class="info-block-head">
                            <h2><i class="fa fa-quote-left" aria-hidden="true"></i> {{ __('About Myself') }}</h2>
                            @if ($canEdit)<a href="{{ route('member.onboarding', ['step' => 6]) }}" class="edit-link" wire:navigate>{{ __('Edit') }}<span class="visually-hidden"> {{ __('About Myself') }}</span></a>@endif
                        </div>
                        <p class="about-text">{{ $profile->about ?? __('Not added yet.') }}</p>
                        @isset($pendingText['profiles.about'])
                            <p class="form-text"><x-ui.badge variant="warning">{{ __('In review') }}</x-ui.badge> {{ $pendingText['profiles.about'] }}</p>
                        @endisset
                    </div>

                    @foreach ($sections as $title => $rows)
                        <div class="information-table" id="{{ \Illuminate\Support\Str::slug($title) }}">
                            <div class="info-block-head">
                                <h2>{{ $title }}</h2>
                            </div>
                            <dl class="info-grid">
                                @foreach ($rows as $label => $value)
                                    <div class="info-pair"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>
</div>
