{{-- A08 community landing page (SEO). The address must end in “-matrimony…”; caste needs its religion. --}}
@inject('masters', 'App\Services\Masters\Masters')
@php
    $religionId = is_numeric($fields['religion_id'] ?? null) ? (int) $fields['religion_id'] : null;
@endphp
<div class="col-12"><x-ui.input :label="__('Address')" name="slug" id="community-slug" wire:model="fields.slug" maxlength="120" required
                                :hint="__('Lower-case words joined by dashes, ending in matrimony, e.g. nair-matrimony or christian-matrimony-kerala')" /></div>
<div class="col-12"><x-ui.input :label="__('Heading')" name="heading" id="community-heading" wire:model="fields.heading" maxlength="120" required /></div>
<div class="col-12"><x-ui.textarea :label="__('Search description')" name="meta_description" id="community-meta" wire:model="fields.meta_description" rows="2" maxlength="200" required /></div>
<div class="col-12 col-md-6">
    <x-ui.select :label="__('Religion (for the member count)')" name="religion_id" id="community-religion" wire:model.live="fields.religion_id"
                 :options="collect($masters->religions())->mapWithKeys(fn ($r) => [$r->id => $r->label])->all()" :placeholder="__('Any religion')" />
</div>
<div class="col-12 col-md-6">
    <x-ui.select :label="__('Caste / community')" name="caste_id" id="community-caste" wire:model="fields.caste_id"
                 :options="$religionId ? collect($masters->castesForReligion($religionId))->mapWithKeys(fn ($c) => [$c->id => $c->label])->all() : []" :placeholder="__('Any')" />
</div>
<div class="col-12"><x-admin.rich-text :label="__('Introduction')" name="intro" model="fields.intro" /></div>
