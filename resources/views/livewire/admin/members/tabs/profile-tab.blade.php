<div class="ui-card">
    <h2 class="h6">{{ __('Profile details') }}</h2>
    @unless ($canEdit)
        <x-ui.alert type="info">{{ __('Read only — editing needs the members.edit permission, and deleted members can\'t be edited.') }}</x-ui.alert>
    @endunless

    <fieldset @disabled(! $canEdit)>
        <legend class="visually-hidden">{{ __('Profile details') }}</legend>
        <div class="row g-3">
            <div class="col-12 col-md-6"><x-ui.input :label="__('First name')" name="form.first_name" wire:model.live.debounce.400ms="form.first_name" required /></div>
            <div class="col-12 col-md-6"><x-ui.input :label="__('Last name')" name="form.last_name" wire:model.live.debounce.400ms="form.last_name" /></div>
            <div class="col-6 col-md-4"><x-ui.input :label="__('Date of birth')" name="form.dob" type="date" wire:model.live="form.dob" /></div>
            <div class="col-6 col-md-4"><x-ui.input :label="__('Height (cm)')" name="form.height_cm" type="number" wire:model.live.debounce.400ms="form.height_cm" /></div>
            <div class="col-6 col-md-4"><x-ui.input :label="__('Weight (kg)')" name="form.weight_kg" type="number" wire:model.live.debounce.400ms="form.weight_kg" /></div>
            <div class="col-6 col-md-4"><x-ui.select :label="__('Marital status')" name="form.marital_status" wire:model.live="form.marital_status" :options="$maritalStatuses" :placeholder="__('Choose…')" /></div>
            <div class="col-6 col-md-4"><x-ui.select :label="__('Religion')" name="form.religion_id" wire:model.live="form.religion_id" :options="$religions" :placeholder="__('Choose…')" /></div>
            <div class="col-6 col-md-4"><x-ui.select :label="__('Caste')" name="form.caste_id" wire:model.live="form.caste_id" :options="$castes" :placeholder="__('Not specified')" /></div>
            <div class="col-12 col-md-6"><x-ui.input :label="__('Sub-caste')" name="form.sub_caste" wire:model.live.debounce.400ms="form.sub_caste" /></div>
            <div class="col-12"><x-ui.textarea :label="__('About me')" name="form.about" wire:model.live.debounce.400ms="form.about" rows="4" /></div>
        </div>
    </fieldset>

    @if ($canEdit)
        <h3 class="h6 mt-4">{{ __('Changes') }}</h3>
        @if ($diff === [])
            <p class="text-muted-brand">{{ __('No changes yet.') }}</p>
        @else
            @foreach ($diff as $row)
                <div class="mb-2">
                    <strong>{{ $row['label'] }}</strong>
                    <div class="mod-diff"><del>{{ $row['old'] }}</del> <ins>{{ $row['new'] }}</ins></div>
                </div>
            @endforeach
            <x-ui.textarea :label="__('Reason (recorded in the audit log)')" name="reason" id="profile-edit-reason" wire:model="reason" rows="2" required />
            @error('form')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror
            <x-ui.button type="button" wire:click="save" loading="save" icon="fa-check">{{ __('Save changes') }}</x-ui.button>
        @endif
    @endif
</div>
