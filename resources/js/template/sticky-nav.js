/* =============================================================================
   STICKY NAVBAR — frosted past the hero, hidden on scroll down, revealed on scroll up
   =============================================================================
   Ported from custom.js. One window listener for the life of the tab; the bar is
   looked up per event because wire:navigate replaces it.
============================================================================= */

let lastScrollY = window.scrollY;

window.addEventListener('scroll', () => {
    const navbar = document.querySelector('.sticky-nav');
    const currentY = window.scrollY;

    if (!navbar) {
        lastScrollY = currentY;
        return;
    }

    /* Frosted surface once we're past the hero. */
    navbar.classList.toggle('menu-fixed', currentY > 100);

    /* Hide on scroll DOWN, reveal on scroll UP, only well past the top. Never hide
       while the mobile menu is open — the menu lives inside the bar. */
    const menuOpen = document.body.classList.contains('nav-open');
    navbar.classList.toggle('nav-hidden', !menuOpen && currentY > lastScrollY && currentY > 200);

    lastScrollY = currentY;
}, { passive: true });
