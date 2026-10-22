{{--
    One membership pricing card (template pricing-cards.php) for one duration: the per-month
    equivalent, what is billed, and the saving against paying monthly. The CTA goes to the plans
    page from the home teaser and to checkout (plan key + duration) from /plans.
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
            <sup>&#8377;</sup><span>{{ $plan->perMonth->format() }}</span><sub>{{ __('/mo') }}</sub>
        </div>
        <p class="billed-text">
            @if ($plan->durationMonths === 1)
                {{ __('Billed monthly') }}
            @else
                {{ __('Billed as ₹:amount for :months months', ['amount' => $plan->price->format(), 'months' => $plan->durationMonths]) }}
                @if ($plan->savingsPercent)
                    · {{ __('save :percent%', ['percent' => $plan->savingsPercent]) }}
                @endif
            @endif
        </p>
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
