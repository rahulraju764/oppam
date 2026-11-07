{{-- A08 ad creative for the member right rail. Dates are IST; banners and carousel slides need an image. --}}
<div class="col-12 col-md-6">
    <x-ui.select :label="__('Slot')" name="slot" id="ad-slot" wire:model="fields.slot" required
                 :options="collect(\App\Enums\AdSlot::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :placeholder="__('Choose a slot')" />
</div>
<div class="col-12 col-md-6">
    <x-ui.select :label="__('Shown to')" name="audience" id="ad-audience" wire:model="fields.audience" required
                 :options="collect(\App\Enums\AdAudience::cases())->mapWithKeys(fn ($a) => [$a->value => $a->label()])->all()" :placeholder="__('Choose who sees it')" />
</div>
<div class="col-12 col-md-4"><x-ui.input :label="__('Weight (1–100)')" name="weight" id="ad-weight" inputmode="numeric" wire:model="fields.weight" required :hint="__('Higher weight is shown more often')" /></div>
<div class="col-12 col-md-4"><x-ui.input :label="__('Starts (IST)')" name="starts_at" id="ad-starts" type="datetime-local" wire:model="fields.starts_at" /></div>
<div class="col-12 col-md-4"><x-ui.input :label="__('Ends (IST)')" name="ends_at" id="ad-ends" type="datetime-local" wire:model="fields.ends_at" /></div>
<div class="col-12"><x-ui.input :label="__('Heading')" name="heading" id="ad-heading" wire:model="fields.heading" maxlength="80" required /></div>
<div class="col-12"><x-ui.textarea :label="__('Text (promo card)')" name="copy" id="ad-copy" wire:model="fields.copy" rows="2" maxlength="200" /></div>
<div class="col-12"><x-ui.input :label="__('Image description')" name="alt" id="ad-alt" wire:model="fields.alt" maxlength="200" /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Button label')" name="cta_label" id="ad-cta-label" wire:model="fields.cta_label" maxlength="40" required /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Link')" name="cta_url" id="ad-cta-url" wire:model="fields.cta_url" maxlength="500" required :hint="__('A page here such as /plans, or an https address')" /></div>
