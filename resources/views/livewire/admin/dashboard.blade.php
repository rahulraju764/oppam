<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Welcome, :name', ['name' => auth('admin')->user()->name]) }}</h1>
            <p>{{ __('The live KPI dashboard and work queues arrive with the modules that feed them.') }}</p>
        </div>
    </div>

    @if ($this->counts !== null)
        <div class="row g-3">
            <div class="col-sm-6 col-xl-4">
                <x-admin.stat-card :label="__('Member accounts')" :value="number_format($this->counts['members'])" :hint="__('All registered accounts')" />
            </div>
            <div class="col-sm-6 col-xl-4">
                <x-admin.stat-card :label="__('Active profiles')" :value="number_format($this->counts['active'])" :hint="__('Visible in search')" />
            </div>
            <div class="col-sm-6 col-xl-4">
                <x-admin.stat-card :label="__('Awaiting moderation')" :value="number_format($this->counts['pending'])" :hint="__('Profiles in PENDING_REVIEW')" />
            </div>
        </div>
    @else
        <x-ui.card>
            <x-ui.empty-state icon="fa-compass" :title="__('Your tools are in the menu')" :message="__('Use the sidebar to reach the areas your role gives you.')" />
        </x-ui.card>
    @endif
</div>
