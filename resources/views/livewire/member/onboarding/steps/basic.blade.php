{{-- Step 1 — basic details (template profile-creation.php; ➕ fields from PRD M02).
     $locked: fields the member can no longer change (R-M02-1) — shown disabled with the reason. --}}
@if ($locked !== [])
    <p class="form-text" id="basic-locked-hint">
        <i class="fa fa-lock" aria-hidden="true"></i>
        {{ __('Some details can’t be changed here once set. Please contact support if one of them is wrong.') }}
    </p>
@endif

<div class="row">
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('First Name')" model="basic.first_name" required maxlength="60" autocomplete="given-name" :placeholder="__('Enter First Name')" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Last Name')" model="basic.last_name" required maxlength="60" autocomplete="family-name" :placeholder="__('Enter Last Name')" />
    </div>
</div>

<x-wizard.option-buttons :label="__('Gender')" model="basic.gender" :selected="$basic->gender" required
    :options="\App\Enums\Gender::options()" :disabled="in_array('gender', $locked, true)" />

<x-wizard.input :label="__('Date Of Birth')" model="basic.dob" type="date" required :disabled="in_array('dob', $locked, true)"
    :hint="__('Brides must be at least 18 and grooms at least 21.')" />

<div class="row">
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.select :label="__('Height')" model="basic.height_cm" :options="$heights" required :placeholder="__('Select Height')" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Weight (kg)')" model="basic.weight_kg" type="number" min="30" max="200" inputmode="numeric" :placeholder="__('Enter Weight (Kg)')" />
    </div>
</div>

<x-wizard.option-buttons :label="__('Marital Status')" model="basic.marital_status" :selected="$basic->marital_status" required
    :options="$maritalStatuses" :disabled="in_array('marital_status', $locked, true)" />

@if ($basic->marital_status !== '' && $basic->marital_status !== \App\Enums\MaritalStatus::NeverMarried->value)
    <x-wizard.input :label="__('Number of Children')" model="basic.children_count" type="number" min="0" max="10" inputmode="numeric" />
@endif

<x-wizard.option-buttons :label="__('Physical Status')" model="basic.physical_status" :selected="$basic->physical_status" required
    :options="$physicalStatuses" />

<x-wizard.option-buttons :label="__('Religion')" model="basic.religion_id" :selected="$basic->religion_id" required
    :options="$religions" :disabled="in_array('religion_id', $locked, true)" />

<x-wizard.select :label="__('Caste')" model="basic.caste_id" :options="$castes"
    :placeholder="$basic->religion_id === '' ? __('Choose a religion first') : __('Select Caste')" />

<div class="row">
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Sub-caste')" model="basic.sub_caste" maxlength="80" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12 d-flex align-items-center">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="basic-caste_no_bar" name="basic-caste_no_bar" wire:model="basic.caste_no_bar">
            <label class="form-check-label" for="basic-caste_no_bar">{{ __('Caste no bar (open to all castes)') }}</label>
        </div>
    </div>
</div>

<x-wizard.select :label="__('Mother Tongue')" model="basic.mother_tongue_id" :options="$motherTongues" required :placeholder="__('Select Mother Tongue')" />

<div class="row">
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.select :label="__('Star')" model="basic.star_id" :options="$stars" :placeholder="__('Select Star')" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.select :label="__('Rasi')" model="basic.rasi_id" :options="$rasis" :placeholder="__('Select Rasi')" />
    </div>
</div>

<div class="row">
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.select :label="__('Chovva Dosham')" model="basic.chovva_dosham" :options="$doshams" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.select :label="__('Papa Dosham')" model="basic.papa_dosham" :options="$doshams" />
    </div>
</div>
