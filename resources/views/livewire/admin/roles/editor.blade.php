<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Roles & permissions') }}</h1>
            <p>{{ __('You can only grant or remove permissions you hold yourself. Changes are audited.') }}</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-3">
            <x-ui.select :label="__('Role')" name="role" wire:model.live="role"
                         :options="$this->roles->mapWithKeys(fn ($r) => [$r->name => \Illuminate\Support\Str::headline($r->name)])->all()" />
        </div>

        <div class="col-lg-9">
            <form wire:submit="save">
                <fieldset @disabled($this->isFixed() || ! auth('admin')->user()->can('roles.edit'))>
                    <legend class="visually-hidden">{{ __('Permissions for :role', ['role' => \Illuminate\Support\Str::headline($role)]) }}</legend>

                    @if ($this->isFixed())
                        <x-ui.alert type="info" class="mb-3">{{ __('The super admin role always has every permission and can’t be edited.') }}</x-ui.alert>
                    @endif

                    <div class="row g-3">
                        @foreach ($this->groups as $group => $permissions)
                            <div class="col-md-6 col-xl-4" wire:key="group-{{ \Illuminate\Support\Str::slug($group) }}">
                                <x-ui.card>
                                    <x-slot:header><strong>{{ __($group) }}</strong></x-slot:header>
                                    @foreach ($permissions as $permission)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="perm-{{ $permission }}" name="permissions[]"
                                                   value="{{ $permission }}" wire:model="selected" @checked($this->isFixed())>
                                            <label class="form-check-label" for="perm-{{ $permission }}"><code>{{ $permission }}</code></label>
                                        </div>
                                    @endforeach
                                </x-ui.card>
                            </div>
                        @endforeach
                    </div>
                </fieldset>

                @if (! $this->isFixed())
                    @can('roles.edit')
                        <div class="mt-4">
                            <x-ui.button loading="save">{{ __('Save permissions') }}</x-ui.button>
                        </div>
                    @endcan
                @endif
            </form>
        </div>
    </div>
</div>
