{{--
    One membership pricing card (template pricing-cards.php). The CTA goes to the plans page
    from the home teaser and to checkout (P5.1) from /plans; with no URL the button is omitted.
    plan   App\Data\Billing\PlanCardData
--}}
@props(['plan'])

<div class="col-lg-4 col-md-6 col-sm-12 col-12 mx-auto text-center">
    <div @class(['pricing-card', 'featured' => $plan->isFeatured])>
        @if ($plan->badge)
            <span class="plan-badge">{{ $plan->badge }}</span>
        @endif
        <h3 class="plan-name">{{ $plan->name }}</h3>
        <hr>
        <div class="plan-price">
            <sup>&#8377;</sup><span>{{ $plan->monthlyPrice->format() }}</span><sub>{{ __('/mo') }}</sub>
        </div>
        <p class="billed-text">{{ __('Billed as ₹:amount per year', ['amount' => $plan->yearlyPrice->format()]) }}</p>
        <hr>
        <p class="unlock-title">{{ __('Unlock Features :') }}</p>
        <ul class="feature-list">
            @foreach ($plan->features as $feature)
                <li><i class="fa fa-check" aria-hidden="true"></i> {{ $feature }}</li>
            @endforeach
        </ul>
        @if ($plan->ctaUrl)
            <div class="purchase-now">
                <a href="{{ $plan->ctaUrl }}" class="btn-purchase" wire:navigate>{{ __('Purchase Now') }}</a>
            </div>
        @endif
    </div>
</div>
