/* =============================================================================
   NAV DRAWER — the mobile hamburger menu  (layouts/partials/header.blade.php)
   =============================================================================
   Ported from custom.js; read docs/template-notes.md "The mobile menu is an
   off-canvas drawer" before changing anything here. In short:

   - Below 992px .navbar-collapse is an off-canvas drawer driven by this code, not by
     Bootstrap's collapse (which animates height and kills the transform slide).
   - The drawer is modal below 992px: role=dialog + aria-modal while open, Tab is
     trapped, focus moves in on a 320ms timer (focus() is dropped mid-transition).
   - It is PORTALLED to <body> below 992px, because a transformed .sticky-nav would
     otherwise trap the fixed drawer and grow the layout viewport. It must go back
     into the nav at 992px+, where Bootstrap's .navbar-expand-lg rule restores the row.
   - The Matches parent is a disclosure <button>; Bootstrap's dropdown owns it on
     desktop only.

   Everything is bound per page with the page's AbortSignal (wire:navigate).
============================================================================= */

import { onPage } from './lifecycle';

onPage((signal) => {
    const toggler = document.querySelector('.custom-toggler');
    const drawer = document.getElementById('navbarSupportedContent');
    const backdrop = document.querySelector('.nav-backdrop');
    if (!toggler || !drawer) return;

    const mq = window.matchMedia('(max-width: 991.98px)');
    const on = (target, type, handler) => target.addEventListener(type, handler, { signal });

    /* Recomputed per call — the Matches sub-menu expands and collapses. */
    const focusables = () => [...drawer.querySelectorAll(
        'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])',
    )].filter((el) => el.offsetParent !== null);

    function setOpen(open) {
        drawer.classList.toggle('open', open);
        document.body.classList.toggle('nav-open', open);
        toggler.setAttribute('aria-expanded', open ? 'true' : 'false');
        // Labels are rendered by Blade through __() — never hard-coded English here.
        toggler.setAttribute('aria-label', open ? toggler.dataset.labelClose : toggler.dataset.labelOpen);

        if (backdrop) {
            if (open) backdrop.hidden = false;
            requestAnimationFrame(() => backdrop.classList.toggle('open', open));
            if (!open) {
                setTimeout(() => {
                    if (!backdrop.classList.contains('open')) backdrop.hidden = true;
                }, 300);
            }
        }

        // The bar carries the drawer; the scroll handler must not translate it away.
        if (open) document.querySelector('.sticky-nav')?.classList.remove('nav-hidden');

        if (open && mq.matches) {
            drawer.setAttribute('role', 'dialog');
            drawer.setAttribute('aria-modal', 'true');
            drawer.setAttribute('aria-label', drawer.dataset.dialogLabel);
            setTimeout(() => {
                if (drawer.classList.contains('open')) focusables()[0]?.focus();
            }, 320);
        } else {
            drawer.removeAttribute('role');
            drawer.removeAttribute('aria-modal');
            drawer.removeAttribute('aria-label');
        }
    }

    /* Close and hand focus back to the control that opened the drawer. */
    function closeAndRestore() {
        setOpen(false);
        if (mq.matches) toggler.focus();
    }

    const matches = drawer.querySelector('.dropdown-toggle');
    const matchesItem = matches ? matches.closest('.dropdown') : null;

    on(toggler, 'click', () => setOpen(!drawer.classList.contains('open')));

    on(drawer, 'click', (e) => {
        if (mq.matches && matchesItem && e.target.closest('.dropdown-toggle')) {
            e.preventDefault();
            matchesItem.classList.toggle('open');
            matches.setAttribute('aria-expanded', matchesItem.classList.contains('open') ? 'true' : 'false');
            return;
        }

        if (e.target.closest('.nav-drawer-close')) {
            closeAndRestore();
            return;
        }

        // A destination: the page is navigating away, don't leave the drawer open behind it.
        if (e.target.closest('a[href]')) setOpen(false);
    });

    if (backdrop) on(backdrop, 'click', closeAndRestore);

    on(document, 'keydown', (e) => {
        if (!drawer.classList.contains('open')) return;

        if (e.key === 'Tab' && mq.matches) {
            const items = focusables();
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            const outside = !drawer.contains(document.activeElement);

            if (e.shiftKey && (outside || document.activeElement === first)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (outside || document.activeElement === last)) {
                e.preventDefault();
                first.focus();
            }
            return;
        }

        if (e.key === 'Escape') closeAndRestore();
    });

    /* PORTAL (below 992px) — see the header comment and template-notes.md. */
    const drawerHome = drawer.parentNode;
    const drawerNext = drawer.nextSibling;
    const backdropHome = backdrop ? backdrop.parentNode : null;

    function syncPortal(isMobile) {
        if (isMobile) {
            if (drawer.parentNode !== document.body) document.body.appendChild(drawer);
            if (backdrop && backdrop.parentNode !== document.body) document.body.appendChild(backdrop);
        } else {
            if (drawer.parentNode !== drawerHome) drawerHome.insertBefore(drawer, drawerNext);
            if (backdrop && backdrop.parentNode !== backdropHome) backdropHome.appendChild(backdrop);
        }
    }

    function syncMode(e) {
        syncPortal(e.matches);

        if (matches) {
            if (e.matches) {
                matches.removeAttribute('data-bs-toggle');
                // Start expanded when the member is already inside Matches.
                const here = matchesItem.classList.contains('open') || matches.classList.contains('active');
                matchesItem.classList.toggle('open', here);
                matches.setAttribute('aria-expanded', here ? 'true' : 'false');
            } else {
                matches.setAttribute('data-bs-toggle', 'dropdown');
                matchesItem.classList.remove('open');
                matches.setAttribute('aria-expanded', 'false');
            }
        }

        if (!e.matches) setOpen(false); // resized up to desktop
    }

    on(mq, 'change', syncMode);
    syncMode(mq);

    // Leaving the page: never carry an open drawer (or its body class) into the next one.
    signal.addEventListener('abort', () => document.body.classList.remove('nav-open'));
});
