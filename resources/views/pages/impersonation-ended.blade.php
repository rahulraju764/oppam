{{--
    "Support session ended" (P1.7b): where "End session" lands. A page on the member site (not a
    redirect into the admin panel) so the browser never makes an automatic cross-domain hop; the
    admin follows the link back. The member code comes from the session flash.
--}}
<x-layouts::public :seo="new \App\Data\Content\SeoData(title: 'Support session ended | Oppam Matrimony', description: 'The support session has ended.', keywords: '')">
    <section class="py-5">
        <div class="container is-readable">
            <div class="ui-card text-center">
                <h1 class="h3">{{ __('Support session ended') }}</h1>
                <p>{{ __('You are no longer signed in as the member. The end of the session has been recorded.') }}</p>
                @php($code = session('impersonation_ended_code'))
                <a class="btn btn-primary" href="{{ is_string($code) ? route('admin.members.show', ['profile' => $code]) : route('admin.members.index') }}">
                    {{ __('Back to the admin panel') }}
                </a>
            </div>
        </div>
    </section>
</x-layouts::public>
