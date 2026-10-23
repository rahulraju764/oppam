{{--
    /safety — Safety Centre (PRD M09). Public page in the about/contact frame (.container.is-readable).
    Tips, how to report and block, emergency contacts (national numbers), and how to reach support.
--}}
<div>
    <section class="safety-section">
        <div class="container is-readable">
            <div class="contact-head">
                <span class="eyebrow">{{ __('Safety Centre') }}</span>
                <h1>{{ __('Stay safe while you find your partner') }}</h1>
                <p>{{ __('Most people on Oppam are here for the same reason you are. These few habits keep you safe from the rare few who are not.') }}</p>
            </div>

            <h2 class="safety-section__title">{{ __('Our safety tips') }}</h2>
            <ul class="safety-tips">
                <li class="safety-tip">
                    <span class="safety-tip__icon" aria-hidden="true"><i class="fa fa-inr"></i></span>
                    <div>
                        <h3>{{ __('Never send money') }}</h3>
                        <p>{{ __('No genuine match asks for money — not for a visa, a medical emergency, a customs fee or a “gift”. If anyone asks, stop and report them.') }}</p>
                    </div>
                </li>
                <li class="safety-tip">
                    <span class="safety-tip__icon" aria-hidden="true"><i class="fa fa-users"></i></span>
                    <div>
                        <h3>{{ __('Involve your family early') }}</h3>
                        <p>{{ __('Let your family speak to theirs before things become serious. Someone who avoids meeting your family is a warning sign.') }}</p>
                    </div>
                </li>
                <li class="safety-tip">
                    <span class="safety-tip__icon" aria-hidden="true"><i class="fa fa-coffee"></i></span>
                    <div>
                        <h3>{{ __('Meet in public') }}</h3>
                        <p>{{ __('For the first meetings choose a busy public place, tell someone where you will be, and travel there on your own.') }}</p>
                    </div>
                </li>
                <li class="safety-tip">
                    <span class="safety-tip__icon" aria-hidden="true"><i class="fa fa-lock"></i></span>
                    <div>
                        <h3>{{ __('Keep personal details private') }}</h3>
                        <p>{{ __('Chat on Oppam until you are comfortable. Never share bank details, OTPs, ID documents or your home address.') }}</p>
                    </div>
                </li>
                <li class="safety-tip">
                    <span class="safety-tip__icon" aria-hidden="true"><i class="fa fa-check-circle"></i></span>
                    <div>
                        <h3>{{ __('Prefer verified profiles') }}</h3>
                        <p>{{ __('The Verified badge means our team checked an ID document. Verify your own profile too — it builds trust both ways.') }}</p>
                    </div>
                </li>
            </ul>

            <h2 class="safety-section__title">{{ __('How to block or report someone') }}</h2>
            <ol class="safety-steps">
                <li>{{ __('Open the member’s profile and choose More → Report, or use Report in your conversation to attach their messages.') }}</li>
                <li>{{ __('Choose what is wrong and add anything we should know. The member is never told who reported them.') }}</li>
                <li>{{ __('Choose More → Block to stop all contact at once. You won’t see each other anywhere on Oppam, and they are not told.') }}</li>
                <li>{{ __('Our safety team reviews every report. Urgent ones — like being asked for money — are looked at within hours.') }}</li>
            </ol>
            @if ($member)
                <p>
                    <a href="{{ route('member.settings.blocked') }}" wire:navigate><i class="fa fa-ban" aria-hidden="true"></i> {{ __('Your blocked & ignored members') }}</a>
                    · <a href="{{ route('member.help') }}" wire:navigate><i class="fa fa-life-ring" aria-hidden="true"></i> {{ __('Ask our support team') }}</a>
                </p>
            @endif

            <h2 class="safety-section__title">{{ __('Emergency contacts (India)') }}</h2>
            <x-ui.alert type="warning">{{ __('If you are in danger, call the police first.') }}</x-ui.alert>
            <ul class="safety-contacts">
                <li><strong><a href="tel:112">112</a></strong> — {{ __('Emergency (police, ambulance, fire)') }}</li>
                <li><strong><a href="tel:181">181</a></strong> — {{ __('Women helpline') }}</li>
                <li><strong><a href="tel:1930">1930</a></strong> — {{ __('Cyber crime and online fraud helpline') }} · <a href="https://cybercrime.gov.in" rel="noopener" target="_blank">cybercrime.gov.in</a></li>
                @foreach ($site->emails as $email)
                    <li><strong><a href="mailto:{{ $email }}">{{ $email }}</a></strong> — {{ __('Oppam support') }}</li>
                @endforeach
            </ul>
        </div>
    </section>
</div>
