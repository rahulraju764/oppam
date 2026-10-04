<x-layouts::public :seo="new \App\Data\Content\SeoData(title: 'Unsubscribed from Saved Search | Oppam Matrimony', description: 'You have been unsubscribed from this saved search alert.', keywords: '')">
    <section class="py-5">
        <div class="container is-readable">
            <div class="ui-card text-center">
                <h1 class="h3">{{ __('Alerts turned off') }}</h1>
                <p>{{ __('You will no longer receive email alerts for the saved search ":name".', ['name' => $searchName]) }}</p>
                <div class="mt-4">
                    <a class="btn btn-primary" href="{{ route('member.search') }}">
                        {{ __('Return to search') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
</x-layouts::public>
