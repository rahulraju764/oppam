{{-- Step 5 — contact details (template contact-details.php; city → country/state/district + city). --}}
<div class="row">
    <div class="col-lg-6 col-md-6 col-12">
        <div class="form-group">
            <label for="contact-mobile">{{ __('Mobile Number') }} *</label>
            <input id="contact-mobile" name="contact-mobile" type="tel" class="form-control" value="{{ $phone }}" readonly
                   aria-describedby="contact-mobile-hint">
            <small id="contact-mobile-hint" class="text-muted-brand">
                <i class="fa fa-check-circle" aria-hidden="true"></i> {{ __('Verified. Change it from Settings after your profile is live.') }}
            </small>
        </div>
    </div>
    <div class="col-lg-6 col-md-6 col-12">
        <x-wizard.input :label="__('Email Address')" model="contact.contact_email" type="email" required maxlength="255"
            autocomplete="email" :placeholder="__('Enter Email Address')" />
    </div>
</div>

<div class="row">
    <div class="col-lg-6 col-md-6 col-12">
        <x-wizard.input :label="__('Alternate Mobile Number')" model="contact.alternate_phone" type="tel" maxlength="20"
            inputmode="tel" :placeholder="__('Enter Alternate Mobile Number')" :hint="__('Indian number, or start with + for abroad.')" />
    </div>
    <div class="col-lg-6 col-md-6 col-12">
        <x-wizard.input :label="__('Convenient Time to Call')" model="contact.convenient_time" maxlength="80" :placeholder="__('e.g. 6 pm – 9 pm')" />
    </div>
</div>

<div class="row">
    <div class="col-lg-6 col-md-6 col-12">
        <x-wizard.input :label="__('Contact Person')" model="contact.contact_person" maxlength="100" :placeholder="__('Name of the person to call')" />
    </div>
    <div class="col-lg-6 col-md-6 col-12">
        <x-wizard.input :label="__('Relationship')" model="contact.contact_relation" maxlength="40" :placeholder="__('e.g. Father')" />
    </div>
</div>

<x-wizard.select :label="__('Country')" model="contact.country_id" :options="$countries" required live :placeholder="__('Select Country')" />

@if ($states !== [])
    <div class="row">
        <div class="col-md-6 col-12">
            <x-wizard.select :label="__('State')" model="contact.state_id" :options="$states" required live :placeholder="__('Select State')" />
        </div>
        <div class="col-md-6 col-12">
            <x-wizard.select :label="__('District')" model="contact.district_id" :options="$districts" required
                :placeholder="$contact->state_id === '' ? __('Choose a state first') : __('Select District')" />
        </div>
    </div>
@endif

<x-wizard.input :label="__('City')" model="contact.city" required maxlength="80" autocomplete="address-level2" :placeholder="__('Enter City / Town')" />

<x-wizard.input :label="__('Address')" model="contact.address_line" textarea maxlength="255" autocomplete="street-address"
    :placeholder="__('Enter your address')" :hint="__('Shown only to members allowed to see your contact details.')" />
