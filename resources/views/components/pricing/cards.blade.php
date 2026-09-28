{{-- The row of pricing cards (template pricing-cards.php wrapper). plans: list<PlanCardData> --}}
@props(['plans'])

<div class="row justify-content-center pricing-row">
    @foreach ($plans as $plan)
        <x-pricing.card :plan="$plan" />
    @endforeach
</div>
