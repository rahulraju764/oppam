{{-- About Us (template about.php). Sections are .container.is-chrome, lined up with the landing page. --}}

<div>
    <section class="about-section">
        <div class="container is-chrome">
            <div class="row align-items-center g-4">
                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-image">
                        <img src="{{ asset('images/home/oppam-proposal-01-800-700.webp') }}" alt="" class="img-fluid" width="800" height="700" fetchpriority="high" decoding="async">
                    </div>
                </div>

                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-content">
                        <p class="eyebrow">{{ __('About Oppam') }}</p>
                        <h1>{{ __('Marriages that begin with trust') }}</h1>
                        <p>{{ __('Oppam Matrimony was built for Malayali families who wanted the reach of an online search without giving up the things that make a Kerala marriage work — verified people, families involved early, and nothing about you shared without your say-so.') }}</p>
                        <p>{{ __('We are not the biggest matrimony site. We are the one where every profile has been checked by a person before anyone can write to it.') }}</p>

                        @include('livewire.public.partials.about-stats', ['headingTag' => 'h2'])
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="about-values">
        <div class="container is-chrome">
            <div class="section-header">
                <p class="eyebrow">{{ __('What we stand for') }}</p>
                <h2>{{ __('Four things we will not compromise on') }}</h2>
                @include('livewire.public.partials.title-divider')
            </div>

            <div class="row g-4">
                @foreach ($values as $value)
                    <div class="col-lg-6 col-md-6 col-12">
                        <div class="value-card">
                            <span class="value-icon">
                                <i class="fa {{ $value['icon'] }}" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h3>{{ __($value['title']) }}</h3>
                                <p>{{ __($value['text']) }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="about-story">
        <div class="container is-readable">
            <h2 class="about-story-head">{{ __('How the site came about') }}</h2>
            <p class="p-main">{{ __('Oppam started as a register kept by a family in Thrissur who had spent two decades arranging marriages in their own community — on paper, by reputation, and by knowing everybody\'s relatives. It worked, and it did not scale past one district.') }}</p>
            <p class="p-main">{{ __('The site is that register, opened up. What we kept was the part that mattered: somebody checks who you are before your profile goes live, and nobody gets your phone number because they paid for it. What we added was reach — a member in Kannur can now be matched with a family in Kollam without either of them knowing the same broker.') }}</p>
            <p class="p-main">
                {{ __('The names on the') }}
                <a href="{{ route('success-stories') }}" wire:navigate>{{ __('success stories page') }}</a>
                {{ __('are real couples who agreed to let us tell you how it went.') }}
            </p>
        </div>
    </section>

    @include('livewire.public.partials.cta', [
        'memberTitle' => __('Your profile is your introduction.'),
        'memberText' => __('A complete profile gets shown to more families across Kerala.'),
        'memberRoute' => 'member.profile.me',
        'memberLabel' => __('My Profile'),
        'guestTitle' => __('Start with a free profile.'),
        'guestText' => __('It takes a few minutes, and nothing is visible until you say so.'),
    ])
</div>
