/* =============================================================================
   PROFILE DROPDOWN — the avatar menu in the member header  (ported from custom.js)
============================================================================= */

import { onPage } from './lifecycle';

onPage((signal) => {
    const toggle = document.querySelector('.profile-toggle');
    const dropdown = document.querySelector('.profile-dropdown');
    if (!toggle || !dropdown) return;

    const setOpen = (open) => {
        dropdown.classList.toggle('active', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        setOpen(!dropdown.classList.contains('active'));
    }, { signal });

    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target)) setOpen(false);
    }, { signal });

    // Close on Escape, and hand focus back to the toggle.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && dropdown.classList.contains('active')) {
            setOpen(false);
            toggle.focus();
        }
    }, { signal });
});
