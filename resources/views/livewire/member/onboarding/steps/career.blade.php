{{-- Step 2 — education & career (template education.php; locations become country → state → district). --}}
<x-wizard.select :label="__('Highest Education')" model="career.education_id" :options="$education" required :placeholder="__('Select Qualification')" />

<x-wizard.input :label="__('Education Details / Institute')" model="career.education_detail" maxlength="150" :placeholder="__('e.g. B.Tech, CUSAT')" />

<x-wizard.option-buttons :label="__('Employed In')" model="career.employer_type" :selected="$career->employer_type" required
    :options="$employerTypes" />

<x-wizard.select :label="__('Occupation')" model="career.occupation_id" :options="$occupations" required :placeholder="__('Select Occupation')" />

<x-wizard.input :label="__('Company / Organisation')" model="career.employer_name" maxlength="150" />

<x-wizard.select :label="__('Annual Income')" model="career.income_band_id" :options="$incomeBands" required :placeholder="__('Select Income')" />

<fieldset class="wizard-fieldset">
    <legend>{{ __('Current Location') }}</legend>
    <x-wizard.select :label="__('Country Living In')" model="career.current_country_id" :options="$countries" required live :placeholder="__('Select Country')" />
    @if ($currentStates !== [])
        <div class="row">
            <div class="col-md-6 col-12">
                <x-wizard.select :label="__('State')" model="career.current_state_id" :options="$currentStates" required live :placeholder="__('Select State')" />
            </div>
            <div class="col-md-6 col-12">
                <x-wizard.select :label="__('District')" model="career.current_district_id" :options="$currentDistricts" required
                    :placeholder="$career->current_state_id === '' ? __('Choose a state first') : __('Select District')" />
            </div>
        </div>
    @endif
    <x-wizard.input :label="__('City / Town')" model="career.current_city" maxlength="80" />
    @if ($career->current_country_id !== '' && $currentStates === [])
        {{-- Living outside India (no states in master data): NRI details (PRD M02 ➕). --}}
        <div class="row">
            <div class="col-md-6 col-12">
                <x-wizard.input :label="__('Citizenship')" model="career.citizenship" maxlength="80" />
            </div>
            <div class="col-md-6 col-12">
                <x-wizard.input :label="__('Visa / Residency Status')" model="career.visa_status" maxlength="80" />
            </div>
        </div>
    @endif
</fieldset>

<fieldset class="wizard-fieldset">
    <legend>{{ __('Permanent Location (native place)') }}</legend>
    <x-wizard.select :label="__('Country')" model="career.permanent_country_id" :options="$countries" required live :placeholder="__('Select Country')" />
    @if ($permanentStates !== [])
        <div class="row">
            <div class="col-md-6 col-12">
                <x-wizard.select :label="__('State')" model="career.permanent_state_id" :options="$permanentStates" required live :placeholder="__('Select State')" />
            </div>
            <div class="col-md-6 col-12">
                <x-wizard.select :label="__('District')" model="career.permanent_district_id" :options="$permanentDistricts" required
                    :placeholder="$career->permanent_state_id === '' ? __('Choose a state first') : __('Select District')" />
            </div>
        </div>
    @endif
    <x-wizard.input :label="__('City / Town')" model="career.permanent_city" maxlength="80" />
</fieldset>
