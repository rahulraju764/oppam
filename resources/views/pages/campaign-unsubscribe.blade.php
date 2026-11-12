{{--
    Campaign email unsubscribe (A14, P8.3b1): the signed link opens the confirmation ($done = false);
    the button POSTs to the same signed URL, which switches off offer emails and shows the result.
--}}
<x-layouts::public :seo="\App\Data\Content\SeoData::private('Unsubscribe | Oppam Matrimony')">
    <section class="py-5">
        <div class="container is-readable">
            <div class="ui-card text-center">
                @if ($done)
                    <h1 class="h3">{{ __('You’re unsubscribed') }}</h1>
                    <p role="status">{{ __('You won’t get offers and announcements from Oppam by email any more. Emails about your account, interests and payments still arrive.') }}</p>
                    <x-ui.button :href="route('member.settings', ['tab' => 'notifications'])" variant="outline">{{ __('Notification settings') }}</x-ui.button>
                @else
                    <h1 class="h3">{{ __('Stop offer emails?') }}</h1>
                    <p>{{ __('Stop offers and announcements from Oppam by email. Emails about your account, interests and payments still arrive.') }}</p>
                    <form method="POST" action="{{ request()->fullUrl() }}">
                        @csrf
                        <x-ui.button>{{ __('Unsubscribe') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </section>
</x-layouts::public>
