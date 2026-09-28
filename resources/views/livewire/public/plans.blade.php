{{--
    Membership plans + FAQ (template package.php; faq.php 301s to #faq). Full-bleed: the 3-up
    pricing grid earns the width. "Purchase Now" appears once checkout exists (P5.1).
--}}
@php($faqColumns = array_chunk($faqs, (int) ceil(count($faqs) / 2)))

<div>
    <section class="page-hero">
        <div class="container is-chrome">
            <div class="page-hero-inner">
                <p class="eyebrow">{{ __('Membership & Help') }}</p>
                <h1>{{ __('Plans, pricing and answers') }}</h1>
                <p>{{ __('Everything you need to decide: what each membership unlocks, what it costs, and answers to the questions we get asked most about profiles, privacy and matches.') }}</p>
                <div class="page-hero-actions">
                    <a href="#plans" class="page-hero-btn">{{ __('View plans') }}</a>
                    <a href="#faq" class="page-hero-btn is-ghost">{{ __('Read the FAQs') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="subscription-section" id="plans">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="subscription-content">
                        <p class="eyebrow">{{ __('Membership Plans') }}</p>
                        <h2>{{ __('Choose the plan that suits you') }}</h2>
                        <p>{{ __('Upgrade to view verified mobile numbers, chat directly with families, and get your profile shown to more matches across Kerala.') }}</p>
                    </div>
                </div>
            </div>

            <x-pricing.cards :plans="$plans" />
        </div>
    </section>

    <section class="faq-section py-5" id="faq">
        <div class="container is-readable">
            <div class="faq-heading text-center">
                <span class="faq-tag">{{ __('FAQ') }}</span>
                <h2>{{ __('Frequently Asked Questions') }}</h2>
                <p>{{ __('Have questions about creating your profile, searching for matches, or premium memberships? Explore our FAQs to get quick and helpful answers.') }}</p>
            </div>

            <div class="row mt-4">
                @foreach ($faqColumns as $column => $items)
                    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                        <div class="accordion custom-accordion" id="faq-col-{{ $column }}">
                            @foreach ($items as $index => $faq)
                                @php($itemId = 'faq-'.$column.'-'.$index)
                                @php($open = $index === 0)
                                <div class="accordion-item">
                                    <h3 class="accordion-header">
                                        <button @class(['accordion-button', 'collapsed' => ! $open]) type="button"
                                                data-bs-toggle="collapse" data-bs-target="#{{ $itemId }}"
                                                aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $itemId }}">
                                            {{ __($faq['question']) }}
                                        </button>
                                    </h3>
                                    <div id="{{ $itemId }}" @class(['accordion-collapse collapse', 'show' => $open]) data-bs-parent="#faq-col-{{ $column }}">
                                        <div class="accordion-body">{{ __($faq['answer']) }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</div>
