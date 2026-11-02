<div class="col-12"><x-ui.input :label="__('Question')" name="question" id="faq-question" wire:model="fields.question" maxlength="300" required /></div>
<div class="col-12"><x-admin.rich-text :label="__('Answer')" name="answer" model="fields.answer" /></div>
