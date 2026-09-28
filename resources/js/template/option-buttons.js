/* =============================================================================
   OPTION BUTTONS — single-choice button groups (.option-btns)  (ported from custom.js)
   =============================================================================
   Visual state only. Where a group feeds a form, the Livewire component owns the
   value (wire:click / wire:model) and the server validates it.
============================================================================= */

document.addEventListener('click', (e) => {
    const button = e.target.closest('.option-btns button');
    if (!button) return;

    button.closest('.option-btns').querySelectorAll('button').forEach((btn) => btn.classList.remove('active'));
    button.classList.add('active');
});
