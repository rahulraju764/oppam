{{-- A12 system health: shown while the panel's readers run (the page is #[Lazy]). --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('System health') }}</h1>
            <p>{{ __('Checking queues, scheduler, webhooks, delivery, live updates, storage and the database…') }}</p>
        </div>
    </div>
    <div class="ui-card" role="status" aria-label="{{ __('Loading…') }}">
        <x-ui.skeleton shape="row" :count="6" />
    </div>
</div>
