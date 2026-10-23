{{--
    Broker / bureau portal layout (oppam.in/broker — PRD §11A B.11): the member site's template
    header / footer and tokens with the bureau sidebar (template dashboard .dashboard-sidebar):
    bureau name, code, role chip and the menu filtered by BureauGate (locked items explain why).
    The 3 / 6 / 3 dashboard row is opened here: the sidebar is the first column, each page renders
    the centre column and the right rail. Pages without a bureau context (apply, device check)
    pass :plain="true" and get a centred single column instead. Never indexed.
--}}
@props(['seo' => null, 'title' => null, 'pageNav' => [], 'plain' => false])

{{-- Always private, even when a page passes its own $seo: these pages name real people. --}}
@php($seo = ($seo ?? \App\Data\Content\SeoData::private($title ?? __('Broker Portal').' | '.config('oppam.site.name')))->asPrivate())
@php($bureau = $plain ? null : \App\Data\Broker\BrokerChromeData::current())
@inject('nav', 'App\Support\Navigation\Navigation')

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['seo' => $seo])
</head>
<body class="is-broker">
    @include('layouts.partials.preloader')
    @include('layouts.partials.header', ['member' => null, 'broker' => $bureau, 'pageNav' => $nav->pageNav($pageNav)])
    <x-ui.toast-stack />

    <main id="main" tabindex="-1">
        @if ($bureau)
            <section class="dashboard-section broker-section">
                <div class="container">
                    <div class="row dashboard-row">
                        <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                            <x-broker.sidebar :bureau="$bureau" />
                        </div>
                        {{ $slot }}
                    </div>
                </div>
            </section>
        @else
            {{ $slot }}
        @endif
    </main>

    @include('layouts.partials.footer', ['member' => null])

    @livewireScripts
</body>
</html>
