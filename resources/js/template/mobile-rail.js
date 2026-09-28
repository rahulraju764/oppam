/* =============================================================================
   MOBILE RAIL — 3/6/3 pages below 992px  (ported from custom.js)
   =============================================================================
   Below 992px the left rail moves into a left-edge drawer (opened by a floating
   button) and promo widgets are woven between result cards; at 992px+ everything
   goes back to its rail. Opt in with markup, no per-page JS:

     <section data-mobile-rail data-rail-label="{{ __('Filters') }}"
              data-rail-open="{{ __('Open Filters') }}" data-rail-close="{{ __('Close Filters') }}">
       … data-rail="menu"   (optional — the drawer's contents)
       … data-rail="weave"  (widgets woven between .profiles rows)

   The FAB and drawer are created per page and removed when the page is left.
============================================================================= */

import { onPage } from './lifecycle';

function buildDrawer({ label, openLabel, closeLabel }) {
    const fab = document.createElement('button');
    fab.type = 'button';
    fab.className = 'profile-fab d-lg-none';
    fab.hidden = true;
    fab.setAttribute('aria-label', openLabel);
    fab.innerHTML = '<i class="fa fa-sliders" aria-hidden="true"></i>';

    const fabLabel = document.createElement('span');
    fabLabel.className = 'profile-fab-label';
    fabLabel.textContent = label; // page-authored attribute: textContent, never innerHTML
    fab.appendChild(fabLabel);

    const drawer = document.createElement('div');
    drawer.className = 'profile-drawer d-lg-none';
    drawer.hidden = true;
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-label', label);
    drawer.innerHTML = '<div class="profile-drawer-panel">'
        + '<button type="button" class="profile-drawer-close"><i class="fa fa-times" aria-hidden="true"></i></button>'
        + '<div class="profile-drawer-body"></div></div>';
    drawer.querySelector('.profile-drawer-close').setAttribute('aria-label', closeLabel);

    return { fab, drawer, body: drawer.querySelector('.profile-drawer-body') };
}

function setUpSection(section, signal) {
    const firstCard = section.querySelector('.profiles');
    if (!firstCard) return;

    const on = (target, type, handler, opts = {}) => target.addEventListener(type, handler, { ...opts, signal });
    const mq = window.matchMedia('(max-width: 991.98px)');
    const gridMq = window.matchMedia('(max-width: 767.98px)'); // the 2-up .profile-grid breakpoint

    const menu = section.querySelector('[data-rail="menu"]');
    const contentParent = firstCard.parentNode;
    const weave = [...section.querySelectorAll('[data-rail="weave"]')];
    // All three labels are rendered by Blade through __(); JS never supplies English.
    const ui = menu ? buildDrawer({
        label: section.dataset.railLabel,
        openLabel: section.dataset.railOpen ?? section.dataset.railLabel,
        closeLabel: section.dataset.railClose ?? section.dataset.railLabel,
    }) : null;

    if (ui) {
        document.body.append(ui.fab, ui.drawer);
        signal.addEventListener('abort', () => {
            ui.fab.remove();
            ui.drawer.remove();
            document.body.classList.remove('drawer-open');
        });
    }

    /* Comment anchors remember each movable element's home for the way back. */
    const movable = menu ? [menu, ...weave] : [...weave];
    const anchors = new Map();
    movable.forEach((el) => {
        const anchor = document.createComment('home');
        el.parentNode.insertBefore(anchor, el);
        anchors.set(el, anchor);
    });

    function closeDrawer() {
        if (!ui) return;
        ui.drawer.classList.remove('open');
        document.body.classList.remove('drawer-open');
        setTimeout(() => {
            if (!ui.drawer.classList.contains('open')) ui.drawer.hidden = true;
        }, 300);
    }

    function toMobile() {
        if (ui && menu.parentNode !== ui.body) ui.body.appendChild(menu);

        const cards = [...contentParent.children].filter((c) => c.classList.contains('profiles'));
        /* A promo may only land at the END of a grid row, or it leaves a hole. */
        const perRow = gridMq.matches ? 2 : 1;
        const step = Math.max(perRow, Math.round(cards.length / (weave.length + 1)));
        let last = -1;

        weave.forEach((widget, i) => {
            let at = Math.min((i + 1) * step - 1, cards.length - 1);
            at = Math.min(Math.ceil((at + 1) / perRow) * perRow - 1, cards.length - 1);
            at = Math.max(at, last);
            last = at;
            if (cards[at]) cards[at].after(widget);
            else contentParent.appendChild(widget);
        });

        if (ui) ui.fab.hidden = false;
    }

    function toDesktop() {
        movable.forEach((el) => {
            const anchor = anchors.get(el);
            if (anchor?.parentNode) anchor.parentNode.insertBefore(el, anchor);
        });
        if (ui) ui.fab.hidden = true;
        closeDrawer();
    }

    if (ui) {
        on(ui.fab, 'click', () => {
            ui.drawer.hidden = false;
            requestAnimationFrame(() => ui.drawer.classList.add('open'));
            document.body.classList.add('drawer-open');
        });
        on(ui.drawer, 'click', (e) => {
            if (e.target === ui.drawer || e.target.closest('.profile-drawer-close')) closeDrawer();
        });
        on(document, 'keydown', (e) => {
            if (e.key === 'Escape') closeDrawer();
        });

        /* Collapse the pill while scrolling down, like the sticky navbar. */
        let lastY = window.scrollY;
        on(window, 'scroll', () => {
            const y = window.scrollY;
            ui.fab.classList.toggle('is-collapsed', y > lastY && y > 200);
            lastY = y;
        }, { passive: true });
    }

    const apply = (e) => (e.matches ? toMobile() : toDesktop());
    on(mq, 'change', apply);
    on(gridMq, 'change', () => {
        if (mq.matches) toMobile();
    });
    apply(mq);
}

onPage((signal) => {
    document.querySelectorAll('[data-mobile-rail]').forEach((section) => setUpSection(section, signal));
});
