{{-- The row of pricing cards (template pricing-cards.php wrapper). plans: list<PlanCardData> --}}
@props(['plans'])

<div class="row justify-content-center pricing-row">
    @forelse ($plans as $plan)
        <x-pricing.card :plan="$plan" />
    @empty
        <div class="col-12">
            <x-ui.card>
                <x-ui.empty-state icon="fa-diamond" :title="__('Membership plans are being updated')"
                                  :message="__('Please check back shortly, or contact us to upgrade.')" />
            </x-ui.card>
        </div>
    @endforelse
</div>
