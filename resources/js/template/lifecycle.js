/* =============================================================================
   PAGE LIFECYCLE — one place that knows about wire:navigate
   =============================================================================
   The template's custom.js ran once per full page load. With Livewire's
   wire:navigate the <body> is swapped WITHOUT re-running the bundle, so:

   - Code that binds to page elements must run again after every navigation
     (`onPage`), and must clean up before the next one (`signal`), or listeners
     on window/document/matchMedia pile up one set per visited page.
   - Code that uses DOCUMENT-level delegation binds once (`once`) and is immune.

   `livewire:navigated` also fires on the very first page load, so onPage covers
   both. When Livewire is not on the page at all (it always is, via the layouts),
   DOMContentLoaded is the fallback.
============================================================================= */

let controller = null;
let pageCount = 0;
const pageInits = [];

function startPage() {
    controller?.abort();
    controller = new AbortController();
    pageCount++;
    pageInits.forEach((init) => {
        try {
            init(controller.signal);
        } catch (error) {
            // One broken widget must not take the rest of the page's chrome down with it.
            console.error(error);
        }
    });
}

/** Run `init(signal)` on every page (first load + each wire:navigate). Abort = page left. */
export function onPage(init) {
    pageInits.push(init);
}

/** How many pages this tab has rendered without a full reload (1 = first load). */
export function pagesSinceLoad() {
    return pageCount;
}

export function boot() {
    let started = false;

    document.addEventListener('livewire:navigated', () => {
        started = true;
        startPage();
    });

    document.addEventListener('livewire:navigating', () => controller?.abort());

    // Livewire fires livewire:navigated on the first load itself; only a page without
    // Livewire needs the fallback (otherwise every widget would initialise twice).
    const fallback = () => {
        if (!started && !window.Livewire) startPage();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(fallback, 0));
    } else {
        setTimeout(fallback, 0);
    }
}

/* Autoplay and counters are motion the user did not ask for. */
export const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
