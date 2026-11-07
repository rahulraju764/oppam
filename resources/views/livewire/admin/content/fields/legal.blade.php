{{-- A08 legal version. The kind is chosen once; the number is given on save; a published version is fixed. --}}
@if ($editing === null)
    <div class="col-12 col-md-6">
        <x-ui.select :label="__('Page')" name="kind" id="legal-kind" wire:model="fields.kind" required
                     :options="collect(\App\Enums\LegalKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all()" :placeholder="__('Choose a page')" />
    </div>
@endif
<div class="col-12 col-md-6"><x-ui.input :label="__('Takes effect on')" name="effective_on" id="legal-effective" type="date" wire:model="fields.effective_on" required /></div>
<div class="col-12"><x-ui.input :label="__('Title')" name="title" id="legal-title" wire:model="fields.title" maxlength="120" required /></div>
<div class="col-12"><x-ui.textarea :label="__('What changed (shown to members)')" name="summary" id="legal-summary" wire:model="fields.summary" rows="2" maxlength="500" /></div>
<div class="col-12"><x-admin.rich-text :label="__('Text')" name="body" model="fields.body" headings /></div>
<div class="col-12">
    <x-ui.checkbox name="is_material" id="legal-material" wire:model="fields.is_material">
        {{ __('Material change: every member must agree again before they can use the site') }}
    </x-ui.checkbox>
    <x-ui.checkbox name="legally_reviewed" id="legal-reviewed" wire:model="fields.legally_reviewed">
        {{ __('Reviewed by our lawyers (hides the “not legally reviewed” note)') }}
    </x-ui.checkbox>
</div>
