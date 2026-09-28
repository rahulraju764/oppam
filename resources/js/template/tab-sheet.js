/* =============================================================================
   TAB BAR + BOTTOM SHEETS  (partials/mobile-tabbar.blade.php, ported from custom.js)
   =============================================================================
   - Sheets open from a <button class="tab-sheet-toggle" aria-controls="…"> —
     delegated on document, so bound once for the life of the tab.
   - measureBar(): the sheet rests on the bar, so the bar's real height is published
     as --tab-bar-h (it changes with font size and the safe-area inset).
   - pinBar(): keeps the bar on screen while the phone's URL bar collapses (fixed
     bottom:0 anchors to the LAYOUT viewport, which drops below the visual one).
============================================================================= */

import { onPage } from './lifecycle';

function measureBar() {
    const bar = document.querySelector('.mobile-footer');
    if (bar && bar.offsetHeight) {
        document.documentElement.style.setProperty('--tab-bar-h', `${bar.offsetHeight}px`);
    }
}

const vv = window.visualViewport;

function pinBar() {
    const bar = document.querySelector('.mobile-footer');
    if (!bar || !vv) return;
    const gap = document.documentElement.clientHeight - (vv.height + vv.offsetTop);
    bar.style.setProperty('--bar-lift', `${gap > 1 ? gap : 0}px`); // >1px: no sub-pixel jitter
}

function close(sheet) {
    sheet.classList.remove('open');
    document.body.classList.remove('sheet-open');
    document.querySelectorAll(`[aria-controls="${sheet.id}"]`)
        .forEach((toggle) => toggle.setAttribute('aria-expanded', 'false'));
    setTimeout(() => {
        if (!sheet.classList.contains('open')) sheet.hidden = true;
    }, 300);
}

window.addEventListener('load', measureBar);
window.addEventListener('resize', measureBar);

if (vv) {
    vv.addEventListener('resize', pinBar);
    vv.addEventListener('scroll', pinBar);
    window.addEventListener('load', pinBar);
}

document.addEventListener('click', (e) => {
    const toggle = e.target.closest('.mobile-footer .tab-sheet-toggle');
    if (toggle) {
        const sheet = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!sheet) return;
        if (sheet.classList.contains('open')) {
            close(sheet);
        } else {
            measureBar();
            sheet.hidden = false;
            requestAnimationFrame(() => sheet.classList.add('open'));
            document.body.classList.add('sheet-open');
            toggle.setAttribute('aria-expanded', 'true');
        }
        return;
    }

    // Backdrop tap and the close button dismiss; a link inside the panel navigates.
    const sheet = e.target.closest('.tab-sheet');
    if (sheet && (e.target === sheet || e.target.closest('.tab-sheet-close'))) close(sheet);
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('.tab-sheet.open').forEach(close);
});

// A new body arrives with each wire:navigate: re-measure it, and never carry an open sheet over.
onPage(() => {
    document.body.classList.remove('sheet-open');
    measureBar();
    pinBar();
});
