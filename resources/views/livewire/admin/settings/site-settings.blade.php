{{--
    A15 site settings (P8.4): every site key from the SettingKey enum, one Save with a reason; the
    logo has its own upload dialog. Links to the admin users and roles screens (A01).
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Site settings') }}</h1>
            <p>{{ __('Name, logo, social links, search-engine defaults, contact details and the maintenance message. Changes apply at once and are audited.') }}</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <form class="ui-card" x-on:submit.prevent="$dispatch('open-modal', { name: 'site-save' })" novalidate>
                <div class="row g-3">
                    @foreach ($keys as $key)
                        @php($field = \App\Livewire\Admin\Settings\SiteSettings::field($key))
                        @php($kind = $key->inputKind())
                        <div class="col-12 {{ in_array($kind, ['text', 'url', 'number'], true) ? 'col-md-6' : '' }}" wire:key="site-{{ $field }}">
                            @if ($kind === 'checkbox')
                                <x-ui.checkbox name="site_{{ $field }}" id="site-{{ $field }}" wire:model="form.{{ $field }}" :error="'form.'.$field" :disabled="! $canEdit">
                                    {{ $key->label() }}
                                </x-ui.checkbox>
                            @elseif ($kind === 'textarea')
                                <x-ui.textarea :label="$key->label()" name="site_{{ $field }}" id="site-{{ $field }}" wire:model="form.{{ $field }}" :error="'form.'.$field" rows="3" :disabled="! $canEdit" />
                            @else
                                <x-ui.input :type="$kind" :label="$key->label().($key->unit() !== '' ? ' ('.$key->unit().')' : '')" name="site_{{ $field }}" id="site-{{ $field }}"
                                            wire:model="form.{{ $field }}" :error="'form.'.$field" :disabled="! $canEdit"
                                            :hint="$kind === 'url' ? __('https:// address; leave empty to hide the icon.') : null" />
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($canEdit)
                    <div class="mt-3">
                        <x-ui.button type="submit" dusk="site-save">{{ __('Save changes') }}</x-ui.button>
                    </div>
                @endif
            </form>
        </div>

        <div class="col-12 col-lg-4">
            <div class="ui-card mb-3">
                <h2 class="h6">{{ __('Logo') }}</h2>
                <img src="{{ $logoUrl }}" alt="{{ __('Current logo') }}" class="img-fluid mb-2" height="80" loading="lazy">
                <p class="small text-muted-brand">{{ $hasCustomLogo ? __('An uploaded logo is in use.') : __('The bundled logo is in use.') }}</p>
                @if ($canEdit)
                    <x-ui.button type="button" variant="secondary" size="sm" x-on:click="$dispatch('open-modal', { name: 'logo-upload' })" dusk="logo-open">
                        {{ __('Change logo') }}
                    </x-ui.button>
                @endif
            </div>
            <div class="ui-card">
                <h2 class="h6">{{ __('Admin users & roles') }}</h2>
                <ul class="list-unstyled small mb-0">
                    @can('staff.view')<li><a href="{{ route('admin.staff.index') }}" wire:navigate>{{ __('Admin users') }}</a></li>@endcan
                    @can('roles.view')<li><a href="{{ route('admin.roles.edit') }}" wire:navigate>{{ __('Roles & permissions') }}</a></li>@endcan
                    <li><a href="{{ route('admin.settings.entitlements') }}" wire:navigate>{{ __('Plans & entitlements') }}</a></li>
                </ul>
            </div>
        </div>
    </div>

    @if ($canEdit)
        <x-admin.confirm-modal name="site-save" :title="__('Save site settings')" action="save" :confirm-label="__('Save')" :danger="false">
            <p class="mb-0">{{ __('Only the fields you changed are saved. Your reason is recorded with each change.') }}</p>
            @if ($errors->hasAny(array_map(fn ($k) => 'form.'.\App\Livewire\Admin\Settings\SiteSettings::field($k), $keys)))
                <x-ui.alert type="danger" class="mt-2">{{ __('Some fields need fixing — close this and check the form.') }}</x-ui.alert>
            @endif
        </x-admin.confirm-modal>

        <x-admin.confirm-modal name="logo-upload" :title="__('Change logo')" action="uploadLogo" reason-model="logoReason" :confirm-label="__('Upload logo')" :danger="false">
            <label class="mat-label" for="logo-file">{{ __('PNG or WebP, up to :kb KB, 100–2000 px', ['kb' => \App\Actions\Admin\Settings\UploadSiteLogo::MAX_KB]) }}</label>
            <input type="file" id="logo-file" name="logo" class="form-control" accept="image/png,image/webp" wire:model="logo">
            <div wire:loading wire:target="logo" class="small text-muted-brand">{{ __('Uploading…') }}</div>
            @error('logo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @if ($hasCustomLogo)
                <x-ui.button type="button" variant="link" size="sm" wire:click="removeLogo" loading="removeLogo" class="mt-2">{{ __('Use the bundled logo instead') }}</x-ui.button>
            @endif
        </x-admin.confirm-modal>
    @endif
</div>
