<div class="col-12 col-md-6"><x-ui.input :label="__('City')" name="city" id="branch-city" wire:model="fields.city" maxlength="80" required /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Role')" name="role" id="branch-role" wire:model="fields.role" maxlength="40" :hint="__('For example, Head office')" required /></div>
<div class="col-12"><x-ui.textarea :label="__('Address')" name="address" id="branch-address" wire:model="fields.address" rows="2" maxlength="300" required /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Phone as shown')" name="phone" id="branch-phone" wire:model="fields.phone" maxlength="40" required /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Phone to dial')" name="tel" id="branch-tel" wire:model="fields.tel" maxlength="16" :hint="__('Digits only, for example +914872441100')" required /></div>
<div class="col-12"><x-ui.input :label="__('Hours')" name="hours" id="branch-hours" wire:model="fields.hours" maxlength="120" required /></div>
<div class="col-12"><x-ui.input :label="__('Map link')" name="map_url" id="branch-map" wire:model="fields.map_url" maxlength="500" :hint="__('An https address')" /></div>
