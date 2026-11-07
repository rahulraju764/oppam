{{--
    /terms, /privacy, /grievance (M12, A08). The template's legal shell (terms.php / privacy.php:
    .privacy-section, .container.is-readable, .privacy-head). The body is the live version's HTML,
    sanitised and formatted on save (HtmlSanitizer::cleanDocument + LegalBodyFormatter), so it is
    printed raw (CLAUDE.md rule 9). Kept in English: legal text is a reviewed document.
--}}
<div>
    <h1 class="visually-hidden">{{ $legalKind->label() }}</h1>

    <section class="privacy-section">
        <div class="container is-readable">
            <div class="row">
                <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                    <div class="row">
                        <div class="col-lg-8 col-md-8 col-sm-12 col-12">
                            <div class="privacy-head">
                                <h2 class="t-head">{{ $document?->title ?? $legalKind->label().' of Oppam Matrimony' }}</h2>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-4 col-sm-12 col-12">
                            <div class="privacy-head">
                                @if ($document)
                                    <p>{{ __('Effective from') }}: {{ $document->effective_on->format('j F Y') }}
                                        <span class="d-block">{{ __('Version :n', ['n' => $document->version]) }}</span></p>
                                @else
                                    <p>{{ __('Last Updated') }}: <span class="legal-fill">[DD Month YYYY]</span></p>
                                @endif
                            </div>
                        </div>
                    </div>
                    <hr>

                    @if (! $document || ! $document->legally_reviewed)
                        <x-ui.alert type="warning">
                            {{ __('This text has not yet been reviewed by our lawyers and may change before launch.') }}
                        </x-ui.alert>
                    @endif

                    @if ($document?->summary)
                        <p class="p-intro"><strong>{{ __('What changed in this version') }}:</strong> {{ $document->summary }}</p>
                    @endif

                    @if ($document)
                        {!! $document->body !!}
                    @else
                        @include($reference)
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>
