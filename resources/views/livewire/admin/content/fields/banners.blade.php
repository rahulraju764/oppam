<div class="col-12"><x-ui.input :label="__('Heading')" name="heading" id="banner-heading" wire:model="fields.heading" maxlength="120" required /></div>
<div class="col-12"><x-ui.textarea :label="__('Text')" name="copy" id="banner-copy" wire:model="fields.copy" rows="2" maxlength="300" required /></div>
<div class="col-12"><x-ui.input :label="__('Image description')" name="alt" id="banner-alt" wire:model="fields.alt" maxlength="200" :hint="__('Leave empty if the image is only decoration')" /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Button label')" name="cta_label" id="banner-cta-label" wire:model="fields.cta_label" maxlength="40" /></div>
<div class="col-12 col-md-6"><x-ui.input :label="__('Button link')" name="cta_url" id="banner-cta-url" wire:model="fields.cta_url" maxlength="500" :hint="__('A page here such as /plans, or an https address')" /></div>
