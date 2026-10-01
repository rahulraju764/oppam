{{-- Step 6 — photos & about (template profile-photos.php + ➕ about me, lifestyle). Upload: P1.4. --}}
<div class="form-group">
    <span class="form-label d-block">{{ __('Profile Photo') }}</span>
    <x-ui.alert type="info">{{ __('Photo upload opens very soon. You can submit your profile now and add photos afterwards — profiles with photos get far more responses.') }}</x-ui.alert>
</div>

<x-wizard.select :label="__('Photo Visibility')" model="about.photo_visibility" :options="$photoVisibilities" required />

<x-wizard.input :label="__('About Me')" model="about.about" textarea required maxlength="1000"
    :placeholder="__('Write a few lines about yourself, your family and what you value')"
    :hint="__('At least :min characters. Please don’t include phone numbers or email addresses.', ['min' => \App\Domain\Profile\ProfileRules::ABOUT_MIN])" />

<div class="row">
    <div class="col-lg-4 col-md-4 col-12">
        <x-wizard.select :label="__('Diet')" model="about.diet_option_id" :options="$diets" />
    </div>
    <div class="col-lg-4 col-md-4 col-6">
        <x-wizard.select :label="__('Smoking')" model="about.smoking_option_id" :options="$smoking" />
    </div>
    <div class="col-lg-4 col-md-4 col-6">
        <x-wizard.select :label="__('Drinking')" model="about.drinking_option_id" :options="$drinking" />
    </div>
</div>

<x-wizard.input :label="__('Hobbies & Interests')" model="about.hobbies" maxlength="400"
    :placeholder="__('e.g. Music, Travel, Cooking')" :hint="__('Separate with commas (up to 10).')" />
