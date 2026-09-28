/* =============================================================================
   PRELOADER  (layouts/partials/preloader.blade.php)
   =============================================================================
   Ported from custom.js. Deliberately plain DOM and imported FIRST by app.js, so a
   failure anywhere else cannot leave the curtain up. The CSS carries an 8s failsafe
   on top of this (see .preloader in template/style.css).

   wire:navigate: the curtain belongs to a real page load only. A body swapped in by
   Livewire also contains it, so it is removed in the swap callback — synchronously,
   before the browser paints. (A class on <html> would not work: Livewire copies the
   new page's <html> attributes over the old ones.)

   MIN_SHOW stops the curtain strobing on a warm cache.
============================================================================= */

const MIN_SHOW = 500;
const started = Date.now();
let hidden = false;

function hide() {
    if (hidden) return;
    hidden = true;

    const pre = document.getElementById('preloader');
    if (!pre) return;

    pre.classList.add('is-loaded');

    // Drop it from the DOM once the fade is done, so it can never eat a click.
    setTimeout(() => pre.remove(), 600);
}

window.addEventListener('load', () => {
    setTimeout(hide, Math.max(0, MIN_SHOW - (Date.now() - started)));
});

// Belt and braces: if 'load' never fires (a hung image, a stalled font), lift it anyway.
setTimeout(hide, 6000);

document.addEventListener('livewire:navigating', (e) => {
    e.detail?.onSwap?.(() => document.getElementById('preloader')?.remove());
});

// Safety net for a navigation that swaps without the callback.
document.addEventListener('livewire:navigated', () => {
    if (hidden) document.getElementById('preloader')?.remove();
});
