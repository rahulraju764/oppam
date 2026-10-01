{{-- Step 3 — family (template family.php; siblings split married / unmarried, ➕ type / values / about). --}}
<div class="row">
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Father\'s Name')" model="family.father_name" required maxlength="100" :placeholder="__('Enter Father\'s Name')" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Father\'s Occupation')" model="family.father_occupation" maxlength="100" :placeholder="__('Enter Occupation')" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Mother\'s Name')" model="family.mother_name" required maxlength="100" :placeholder="__('Enter Mother\'s Name')" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Mother\'s Occupation')" model="family.mother_occupation" maxlength="100" :placeholder="__('Enter Occupation')" />
    </div>
</div>

<div class="row">
    @foreach ([
        'brothers_married' => __('Brothers (married)'),
        'brothers_unmarried' => __('Brothers (unmarried)'),
        'sisters_married' => __('Sisters (married)'),
        'sisters_unmarried' => __('Sisters (unmarried)'),
    ] as $field => $label)
        <div class="col-lg-6 col-md-6 col-sm-6 col-6">
            <x-wizard.input :label="$label" :model="'family.'.$field" type="number" min="0" max="15" inputmode="numeric" />
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.select :label="__('Family Status')" model="family.family_status_option_id" :options="$familyStatuses" required :placeholder="__('Select Status')" />
    </div>
    <div class="col-lg-6 col-md-6 col-sm-6 col-12">
        <x-wizard.input :label="__('Native Place')" model="family.native_place" maxlength="100" />
    </div>
</div>

<x-wizard.option-buttons :label="__('Family Type')" model="family.family_type_option_id" :selected="$family->family_type_option_id"
    :options="$familyTypes" />

<x-wizard.option-buttons :label="__('Family Values')" model="family.family_values_option_id" :selected="$family->family_values_option_id"
    :options="$familyValues" />

<x-wizard.input :label="__('About Family')" model="family.about_family" textarea maxlength="1000"
    :placeholder="__('A few lines about your family')" />
