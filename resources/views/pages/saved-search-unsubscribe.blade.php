{{--
    Saved-search alert unsubscribe (M04): the email link opens the confirmation ($done = false);
    the button POSTs to the same URL, which turns the alerts off and shows the result.
--}}
<x-layouts::public :seo="\App\Data\Content\SeoData::private('Saved search alerts | Oppam Matrimony')">
    <section class="py-5">
        <div class="container is-readable">
            <div class="ui-card text-center">
                @if ($done)
                    <h1 class="h3">{{ __('Alerts turned off') }}</h1>
                    <p role="status">{{ __('You will no longer receive email alerts for the saved search ":name".', ['name' => $searchName]) }}</p>
                    <x-ui.button :href="route('member.search')">{{ __('Return to search') }}</x-ui.button>
                @else
                    <h1 class="h3">{{ __('Turn off alerts?') }}</h1>
                    <p>{{ __('Stop the email alerts for your saved search ":name". The search itself stays saved.', ['name' => $searchName]) }}</p>
                    <form method="POST" action="{{ route('saved-searches.unsubscribe', ['token' => $token]) }}">
                        @csrf
                        <x-ui.button>{{ __('Turn off alerts') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </section>
</x-layouts::public>
