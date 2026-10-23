<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content">
            <div class="content-header">
                <h1>{{ $title }}</h1>
            </div>
            <x-ui.empty-state icon="fa-wrench" :title="__('Opening soon')" :message="__('This part of the bureau portal opens soon.')">
                <x-ui.button :href="route('broker.dashboard')" variant="outline" wire:navigate>{{ __('Back to the dashboard') }}</x-ui.button>
            </x-ui.empty-state>
        </div>
    </div>
    <div class="col-lg-3 col-md-12 col-sm-12 col-12"></div>
</div>
