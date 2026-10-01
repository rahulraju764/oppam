{{-- Step 4 — partner preferences (template partner.php; ➕ fields from PRD M02). Empty = any. --}}
<div class="row">
    <div class="col-12">
        <h4 class="preference-heading">{{ __('Basic & Personal Preference') }}</h4>
    </div>

    <div class="col-12">
        <div class="form-group mb-0">
            <span class="form-label d-block" id="preference-age-label">{{ __('Age') }} *</span>
            <div class="row">
                <div class="col-6">
                    <x-wizard.select :label="__('Age: From')" model="preference.age_min" :options="$ages" required :placeholder="__('From')" class="label-visually-hidden" />
                </div>
                <div class="col-6">
                    <x-wizard.select :label="__('Age: To')" model="preference.age_max" :options="$ages" required :placeholder="__('To')" class="label-visually-hidden" />
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="form-group mb-0">
            <span class="form-label d-block">{{ __('Height') }}</span>
            <div class="row">
                <div class="col-6">
                    <x-wizard.select :label="__('Height: From')" model="preference.height_min_cm" :options="$heights" :placeholder="__('From')" class="label-visually-hidden" />
                </div>
                <div class="col-6">
                    <x-wizard.select :label="__('Height: To')" model="preference.height_max_cm" :options="$heights" :placeholder="__('To')" class="label-visually-hidden" />
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <x-wizard.multi-option-buttons :label="__('Marital Status')" list="marital_statuses" :options="$maritalStatuses" :selected="$preference->marital_statuses" />
        <x-wizard.multi-option-buttons :label="__('Physical Status')" list="physical_statuses" :options="$physicalStatuses" :selected="$preference->physical_statuses" />
    </div>

    <div class="col-12">
        <h4 class="preference-heading">{{ __('Religious Preference') }}</h4>
    </div>

    <div class="col-12">
        <x-wizard.multi-option-buttons :label="__('Religion')" list="religion_ids" :options="$religions" :selected="$preference->religion_ids" required />
        <x-wizard.check-list :label="__('Caste')" model="preference.caste_ids" :options="$castes" :empty="__('Choose a religion first.')" />
        <x-wizard.check-list :label="__('Mother Tongue')" model="preference.mother_tongue_ids" :options="$motherTongues" />
        <x-wizard.check-list :label="__('Star')" model="preference.star_ids" :options="$stars" />
    </div>

    <div class="col-12">
        <h4 class="preference-heading">{{ __('Education & Career') }}</h4>
    </div>

    <div class="col-12">
        <x-wizard.check-list :label="__('Education')" model="preference.education_ids" :options="$education" />
        <x-wizard.check-list :label="__('Occupation')" model="preference.occupation_ids" :options="$occupations" />
        <x-wizard.select :label="__('Minimum Annual Income')" model="preference.min_income_band_id" :options="$incomeBands" :placeholder="__('Any income')" />
    </div>

    <div class="col-12">
        <h4 class="preference-heading">{{ __('Location & Lifestyle') }}</h4>
    </div>

    <div class="col-12">
        <x-wizard.check-list :label="__('Country Living In')" model="preference.country_ids" :options="$countries" />
        <x-wizard.check-list :label="__('District')" model="preference.district_ids" :options="$districts" />
        <x-wizard.multi-option-buttons :label="__('Diet')" list="diet_option_ids" :options="$diets" :selected="$preference->diet_option_ids" />
        <x-wizard.input :label="__('About Your Partner')" model="preference.about_partner" textarea maxlength="1000"
            :placeholder="__('Describe the partner you are looking for')" />
    </div>
</div>
