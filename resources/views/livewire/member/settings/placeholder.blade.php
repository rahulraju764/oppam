{{-- Skeleton while a /settings tab loads (#[Lazy]). --}}
<div class="ui-card" aria-busy="true">
    <span class="visually-hidden">{{ __('Loading…') }}</span>
    <x-ui.skeleton shape="line" :count="6" />
</div>
