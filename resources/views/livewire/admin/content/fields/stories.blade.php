<div class="col-12"><x-ui.input :label="__('Couple')" name="couple" id="story-couple" wire:model="fields.couple" maxlength="120" required /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Place')" name="place" id="story-place" wire:model="fields.place" maxlength="80" required /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Wedding date')" name="wedding_date" id="story-date" type="date" wire:model="fields.wedding_date" required /></div>
<div class="col-12"><x-ui.textarea :label="__('Quote')" name="quote" id="story-quote" wire:model="fields.quote" rows="2" maxlength="300" required /></div>
<div class="col-12"><x-admin.rich-text :label="__('Their story')" name="body" model="fields.body" /></div>
<div class="col-12">
    <fieldset>
        <legend class="h6">{{ __('Consent (required to publish)') }}</legend>
        <x-ui.checkbox name="consentFirst" id="story-consent-a" wire:model="consentFirst">{{ __('First partner consented') }}</x-ui.checkbox>
        <x-ui.checkbox name="consentSecond" id="story-consent-b" wire:model="consentSecond">{{ __('Second partner consented') }}</x-ui.checkbox>
        <x-ui.textarea :label="__('How consent was given')" name="consentNote" id="story-consent-note" wire:model="consentNote" rows="2" maxlength="500" :hint="__('On a published story, consent only changes by withdrawing it.')" />
    </fieldset>
</div>
