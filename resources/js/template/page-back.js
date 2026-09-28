/* =============================================================================
   PAGE BACK BUTTON — [< Back] in <x-page-nav>  (ported from custom.js)
   =============================================================================
   The href is the page's PARENT (right for a deep link, a new tab, no JS). When
   the visitor actually came from another page of this site, history.back() is
   better: it restores scroll position and doesn't grow the history stack.

   With wire:navigate, document.referrer is NOT updated between pages, so "came
   from this site" is also true once this tab has rendered more than one page.
============================================================================= */

import { pagesSinceLoad } from './lifecycle';

function cameFromThisSite() {
    if (pagesSinceLoad() > 1) return true;

    try {
        return !!document.referrer && new URL(document.referrer).origin === window.location.origin;
    } catch {
        return false;
    }
}

document.addEventListener('click', (e) => {
    const back = e.target.closest('[data-page-back]');
    if (!back) return;

    // Modified clicks (new tab / window / download) keep their normal behaviour.
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    // history.length > 1 guards a tab opened straight onto this URL.
    if (cameFromThisSite() && window.history.length > 1) {
        e.preventDefault();
        e.stopImmediatePropagation(); // don't let wire:navigate also follow the href
        window.history.back();
    }
}, true);
