<div class="col-12 col-md-6"><x-ui.input :label="__('Name')" name="name" id="testimonial-name" wire:model="fields.name" maxlength="80" required /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Place')" name="place" id="testimonial-place" wire:model="fields.place" maxlength="80" required /></div>
<div class="col-12"><x-ui.textarea :label="__('Quote')" name="quote" id="testimonial-quote" wire:model="fields.quote" rows="3" maxlength="500" required /></div>
<div class="col-12"><x-ui.checkbox name="consentFirst" id="testimonial-consent" wire:model="consentFirst">{{ __('This person agreed to their words and photo being published') }}</x-ui.checkbox></div>
