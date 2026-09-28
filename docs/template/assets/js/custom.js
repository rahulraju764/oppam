/* ===============================
   PRELOADER  (assets/includes/header.php)
   ===============================
   Deliberately OUTSIDE the jQuery ready block and first in the file: it must register even if
   something below it throws, or a JS error would leave the curtain over the whole site. It is
   also plain DOM, not jQuery, so it survives jQuery itself failing to load. The CSS carries an
   8s failsafe on top of this — see .preloader in style.css.

   MIN_SHOW stops the curtain strobing on a warm cache: below ~400ms a preloader reads as a
   flash of junk rather than a load. */
(function () {

    var pre = document.getElementById('preloader');
    if (!pre) return;

    var MIN_SHOW = 500;
    var started = Date.now();
    var hidden = false;

    function hide() {

        if (hidden) return;
        hidden = true;

        pre.classList.add('is-loaded');

        // Drop it from the DOM once the fade is done, so it can never eat a click.
        setTimeout(function () {
            if (pre.parentNode) pre.parentNode.removeChild(pre);
        }, 600);
    }

    window.addEventListener('load', function () {
        setTimeout(hide, Math.max(0, MIN_SHOW - (Date.now() - started)));
    });

    // Belt and braces: if 'load' never fires (a hung image, a stalled font), lift it anyway.
    setTimeout(hide, 6000);

})();


/* ===============================
   DOM READY
   ===============================
   This was `$(function () { … })`, and that call was the LAST thing on the site
   using jQuery — everything else in this file is already plain DOM, and no page
   carries an inline script. So jQuery (85KB) is gone from script.php, and this
   is its one-line replacement. Bootstrap 5 has never needed it.

   `interactive` is enough: script.php loads after both footers, so the elements
   below already exist; readyState is only ever 'loading' here if a future change
   moves the tag into <head>. */
function onReady(fn) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
}

onReady(function () {

    /* AOS.init() used to sit here. AOS was initialised on every page and animated
       nothing: no element on the site has ever carried a data-aos attribute. It
       is out of links.php/script.php along with WOW.js, animate.css and Magnific
       Popup — all four were loaded site-wide and all four were unreferenced.
       To bring AOS back: re-add the two tags and call AOS.init() here. */

    /* =========================
          MATCH SLIDER
    ========================= */
    /* Breakpoints are VIEWPORT width, but the slider lives in the dashboard's
       centre column — which is col-6 since the ads rail landed, not col-9. So
       the counts step back down at 992 (where the 3-column split kicks in) and
       only climb again once the column is genuinely wide. */
    /* Per element, not per selector: dashboard.php has TWO .match-slider rails
       (Daily Recommendations and All Matches). See the CAROUSELS block below. */
    eachSwiper(".match-slider", function (el) {
    new Swiper(el, {
        loop: true,

        breakpoints: {
            0: {          // phones: two cards side by side, matching the members grid
                slidesPerView: 2,
                spaceBetween: 5
            },
            480: {
                slidesPerView: 2,
                spaceBetween: 5
            },
            768: {          // still full-width: columns have not split yet
                slidesPerView: 3,
                spaceBetween: 5
            },
            992: {          // 3-column split starts — centre column is ~470px
                slidesPerView: 3,
                spaceBetween: 10
            },
            1400: {
                slidesPerView: 3,
                spaceBetween: 10
            },
            1700: {
                slidesPerView: 3,
                spaceBetween: 10
            }
        }
        });
    });

    /* =========================
          COUNTDOWN TIMER
    ========================= */

    const timer = document.querySelector(".time-card h4");

    if (timer) {

        let totalTime = 10 * 60 * 60;

        function updateTimer() {
            let hours = Math.floor(totalTime / 3600);
            let minutes = Math.floor((totalTime % 3600) / 60);
            let seconds = totalTime % 60;

            hours = String(hours).padStart(2, "0");
            minutes = String(minutes).padStart(2, "0");
            seconds = String(seconds).padStart(2, "0");

            timer.innerHTML = `${hours}h : ${minutes}m : ${seconds}s`;

            if (totalTime > 0) {
                totalTime--;
            }
        }

        updateTimer();
        setInterval(updateTimer, 1000);
    }

    /* =========================
          STICKY NAVBAR
    ========================= */

    const navbar = document.querySelector(".sticky-nav");

    if (navbar) {
        let lastScrollY = window.scrollY;

        window.addEventListener("scroll", function () {
            const currentY = window.scrollY;

            /* Frosted surface once we're past the hero. */
            if (currentY > 100) {
                navbar.classList.add("menu-fixed");
            } else {
                navbar.classList.remove("menu-fixed");
            }

            /* Hide on scroll DOWN, reveal on scroll UP. Only hide once we're
               well past the top so the nav never flickers near the hero.
               Never hide while the mobile menu is open — the menu lives INSIDE
               the bar, so translating it up would shoot the open panel off-screen. */
            const menuOpen = document.body.classList.contains("nav-open");
            if (!menuOpen && currentY > lastScrollY && currentY > 200) {
                navbar.classList.add("nav-hidden");
            } else {
                navbar.classList.remove("nav-hidden");
            }

            lastScrollY = currentY;
        }, { passive: true });
    }

});

/* =========================
      CAROUSELS  —  Swiper only
   =========================
   The site used to run TWO carousel libraries: Swiper for the dashboard match
   sliders, Owl for everything else. They do the same job, so Owl is gone — with
   it jquery.owl (~45KB), owl.carousel.min.css and owl.theme.default.min.css.

   `$('.similar-sec').owlCarousel(...)` was also removed outright: no page has
   ever contained a `.similar-sec` element. It was initialising nothing.

   TWO RULES for every init below.

   1. Init per ELEMENT, never per selector string. `.ad-oppam` appears twice on
      search.php (once in the page, once inside the ads.php rail) and
      `.match-slider` twice on dashboard.php. Passing a selector leaves the
      behaviour dependent on how the library treats a multi-match, which is not
      something this code should rely on.

   2. Scope pagination and navigation to the container. A bare
      '.swiper-pagination' resolves to the FIRST one in the document, so two
      carousels on one page would drive the same dots — click the ad rail, watch
      the hero's dots move. */

/* Autoplay is motion the user did not ask for. Everything below honours the OS
   setting; the carousels stay swipeable, they just stop moving on their own. */
var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function autoplayOpts(delay) {
    if (prefersReducedMotion) return false;
    return { delay: delay, disableOnInteraction: false, pauseOnMouseEnter: true };
}

/* Run `fn(element)` for every match — see rule 1. */
function eachSwiper(selector, fn) {
    document.querySelectorAll(selector).forEach(fn);
}

/* ---- Sponsored ad rail: one at a time, dots, autoplay ---- */
eachSwiper('.ad-oppam', function (el) {
    new Swiper(el, {
        loop: true,
        spaceBetween: 10,
        slidesPerView: 1,
        autoplay: autoplayOpts(5000),
        pagination: {
            el: el.querySelector('.swiper-pagination'),
            clickable: true
        }
    });
});

/* =========================
      PROFILE DROPDOWN
========================= */

document.addEventListener("DOMContentLoaded", function () {

    const profileToggle = document.querySelector(".profile-toggle");
    const profileDropdown = document.querySelector(".profile-dropdown");

    if (profileToggle && profileDropdown) {

        const setOpen = (open) => {
            profileDropdown.classList.toggle("active", open);
            profileToggle.setAttribute("aria-expanded", open ? "true" : "false");
        };

        // Toggle dropdown on click
        profileToggle.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();

            setOpen(!profileDropdown.classList.contains("active"));
        });

        // Close when clicking outside
        document.addEventListener("click", function (e) {
            if (!profileDropdown.contains(e.target)) {
                setOpen(false);
            }
        });

        // Close on Escape, and hand focus back to the toggle
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && profileDropdown.classList.contains("active")) {
                setOpen(false);
                profileToggle.focus();
            }
        });

    }

});

// Trigger when section is visible (Intersection Observer)
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const counter = entry.target;
            animateCounter(counter);
            observer.unobserve(counter); // run only once
        }
    });
}, { threshold: 0.5 });

const counters = document.querySelectorAll(".counter");

function animateCounter(counter) {

    const target = +counter.dataset.target;
    let count = 0;

    function update() {

        const increment = target / 150;

        count += increment;

        if (count < target) {

            counter.innerText = Math.ceil(count);

            requestAnimationFrame(update);

        } else {

            counter.innerText = target;

        }

    }

    update();

}

// <!-- /*================ PROFILE CREATION ================*/ -->

// document.querySelectorAll(".step-item").forEach(item => {
//     item.addEventListener("click", function () {

//         document.querySelectorAll(".step-item").forEach(step => {
//             step.classList.remove("active");
//         });

//         this.classList.add("active");

//     });
// });

counters.forEach(counter => observer.observe(counter));

/* =========================
      HERO CAROUSEL  (index.php)
   =========================
   Two things beyond a plain slider, both inherited from the Owl build:

   • The prev/next buttons were round photo thumbnails — the current slide on the
     left, the next on the right, under a white veil that lifted on hover. They
     are plain chevron discs now; the dots already carry the photos, so this code
     no longer paints backgrounds onto the buttons.
   • The dots are the slide photos, each with the slide's heading as a tooltip.
     Both used to be duplicated in the markup — every slide carried a
     data-dot="<img src=…>" attribute repeating its own image, and the headings
     were a second hardcoded array here that had to stay in step with the <h2>s.
     Both are now READ OFF THE SLIDES, so a fifth slide needs no JS change.
   • .basement-details animates in on each slide change; the class is removed and
     re-added so the animation restarts. */

(function () {

    var hero = document.querySelector('.basement-carousel');
    if (!hero) return;

    var slides = Array.prototype.slice.call(hero.querySelectorAll('.swiper-slide'));

    /* Read the dot image + tooltip out of each slide — one source of truth. */
    var slideData = slides.map(function (slide) {
        var img = slide.querySelector('.basement-images img');
        var h2  = slide.querySelector('.basement-details h2');
        return {
            src: img ? img.getAttribute('src') : '',
            title: h2 ? h2.textContent.trim() : ''
        };
    });

    var prevBtn = hero.querySelector('.swiper-button-prev');
    var nextBtn = hero.querySelector('.swiper-button-next');

    function animateActive(swiper) {
        hero.querySelectorAll('.basement-details').forEach(function (el) {
            el.classList.remove('animate-content');
        });
        var active = swiper.slides[swiper.activeIndex];
        if (!active) return;
        var details = active.querySelector('.basement-details');
        if (!details) return;
        // Next frame, so removing and re-adding the class restarts the animation
        // instead of collapsing into no change at all.
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                details.classList.add('animate-content');
            });
        });
    }

    new Swiper(hero, {
        loop: true,
        slidesPerView: 1,
        // Swiper's 300ms default reads as a snap on a full-bleed hero photo.
        speed: 900,
        autoplay: autoplayOpts(7000),

        navigation: {
            prevEl: prevBtn,
            nextEl: nextBtn
        },

        pagination: {
            el: hero.querySelector('.swiper-pagination'),
            clickable: true,
            renderBullet: function (index, className) {
                var d = slideData[index] || { src: '', title: '' };
                // data-title feeds the ::after tooltip in style.css.
                return '<button type="button" class="' + className + '" data-title="'
                     + d.title.replace(/"/g, '&quot;') + '" aria-label="Go to slide '
                     + (index + 1) + ': ' + d.title.replace(/"/g, '&quot;') + '">'
                     + '<img src="' + d.src + '" alt="" width="55" height="55">'
                     + '</button>';
            }
        },

        on: {
            init: function () {
                animateActive(this);
            },
            slideChange: function () {
                animateActive(this);
            }
        }
    });

})();

/* ---- Success stories (single-profile.php): one at a time, no chrome ---- */
eachSwiper('.stories', function (el) {
    new Swiper(el, {
        loop: true,
        slidesPerView: 1,
        spaceBetween: 10,
        autoplay: autoplayOpts(5000)
    });
});

/* ---- Testimonials (index.php): 1 / 2 / 3 / 4 across, dots ----
   Owl's `responsive` keys and Swiper's `breakpoints` keys are both min-width,
   so the four steps carry over unchanged. */
eachSwiper('.testimonial-carousel', function (el) {
    new Swiper(el, {
        loop: true,
        spaceBetween: 24,
        slidesPerView: 1,
        autoplay: autoplayOpts(5000),
        pagination: {
            el: el.querySelector('.swiper-pagination'),
            clickable: true
        },
        breakpoints: {
            576:  { slidesPerView: 2 },
            992:  { slidesPerView: 3 },
            1200: { slidesPerView: 4 }
        }
    });
});


document.querySelectorAll('.option-btns').forEach(group => {

    const buttons = group.querySelectorAll('button');

    buttons.forEach(button => {

        button.addEventListener('click', function () {

            buttons.forEach(btn => {
                btn.classList.remove('active');
            });

            this.classList.add('active');

        });

    });

});

const dob = document.getElementById("dob");

/* Only the wizard has #dob. Guard it — an unguarded dob.max throws on every other
   page (my-matches.php, etc.), which halts any script that follows. */
if (dob) {
    let today = new Date();
    let maxDate = new Date(
        today.getFullYear() - 18,
        today.getMonth(),
        today.getDate()
    );

    dob.max = maxDate.toISOString().split("T")[0];
}


/* =========================
   MOBILE RAIL — dashboard-style 3-column pages (profile, interest, matches, search)
   Below 992px the left filter/menu rail would stack on top of the content. Instead:
   move the menu into a left-edge drawer (opened by a floating button) and weave the
   promo/ad widgets among the result cards. Above 992px everything is restored to its
   rail. Opt in per page with markup, no per-page JS:

     - the <section>            data-mobile-rail  [data-rail-label="Filters"]
     - element(s) for the drawer   data-rail="menu"
     - widgets to weave in         data-rail="weave"

   The content column is auto-detected as the parent of the first .profiles row.
========================= */
(function () {
    const mq = window.matchMedia("(max-width: 991.98px)");
    /* The 2-up .profile-grid breakpoint. The weave has to know about it — see
       toMobile() — and re-run when it is crossed, which the 992px query alone
       would never notice. */
    const gridMq = window.matchMedia("(max-width: 767.98px)");

    document.querySelectorAll("[data-mobile-rail]").forEach(section => {

        const firstCard = section.querySelector(".profiles");
        /* The menu is OPTIONAL. interest.php opts out of it — its filters moved
           into the Interests bottom sheet on the mobile tab bar — but it still
           wants the ad widgets woven into the column, so a section with no
           [data-rail="menu"] gets the weaving and no FAB/drawer at all. */
        const menu = section.querySelector('[data-rail="menu"]');
        if (!firstCard) return;

        const contentParent = firstCard.parentNode;
        const weave = [...section.querySelectorAll('[data-rail="weave"]')];

        /* Build the FAB + drawer for this section — only when there is a menu. */
        let fab = null, drawer = null, drawerBody = null;

        if (menu) {
            const label = section.getAttribute("data-rail-label") || "Filters";
            fab = document.createElement("button");
            fab.type = "button";
            fab.className = "profile-fab d-lg-none";
            fab.hidden = true;
            fab.setAttribute("aria-label", "Open " + label);
            /* Extended FAB: icon AND the section's own label. An icon-only circle
               didn't say what it opened, and the three sections that build one mean
               three different things by it (Filters / Browse / My Matches). The
               label goes in via textContent, not innerHTML — it comes from a
               page-authored attribute. */
            fab.innerHTML = '<i class="fa fa-sliders" aria-hidden="true"></i>';
            const fabLabel = document.createElement("span");
            fabLabel.className = "profile-fab-label";
            fabLabel.textContent = label;
            fab.appendChild(fabLabel);

            drawer = document.createElement("div");
            drawer.className = "profile-drawer d-lg-none";
            drawer.hidden = true;
            drawer.setAttribute("role", "dialog");
            drawer.setAttribute("aria-modal", "true");
            drawer.setAttribute("aria-label", label);
            drawer.innerHTML =
                '<div class="profile-drawer-panel">' +
                '<button type="button" class="profile-drawer-close" aria-label="Close ' + label + '">' +
                '<i class="fa fa-times" aria-hidden="true"></i></button>' +
                '<div class="profile-drawer-body"></div></div>';
            drawerBody = drawer.querySelector(".profile-drawer-body");
            document.body.append(fab, drawer);
        }

        /* Remember every movable element's home with a comment anchor, so it goes
           back to exactly the right spot when resizing up to desktop. */
        const movable = menu ? [menu, ...weave] : [...weave];
        const anchors = new Map();
        movable.forEach(el => {
            const a = document.createComment("home");
            el.parentNode.insertBefore(a, el);
            anchors.set(el, a);
        });

        function toMobile() {
            if (menu && menu.parentNode !== drawerBody) drawerBody.appendChild(menu);

            const cards = [...contentParent.children].filter(c => c.classList.contains("profiles"));

            /* Below 768px the rows lay out 2-up inside .profile-grid, and a woven
               promo spans the full width (see responsive.css). So a promo may only
               land at the END OF A ROW — dropped after an odd card it pushes the
               next one onto a fresh row and leaves a hole in the grid, which is
               what one promo per card did: every second cell came up empty. */
            const perRow = gridMq.matches ? 2 : 1;

            /* At least one full row of content between promos, then snap the
               insertion point to that row's last card. */
            const step = Math.max(perRow, Math.round(cards.length / (weave.length + 1)));
            let last = -1;
            weave.forEach((w, i) => {
                let at = Math.min((i + 1) * step - 1, cards.length - 1);
                at = Math.min(Math.ceil((at + 1) / perRow) * perRow - 1, cards.length - 1);
                /* Two promos snapping to the same row stack after it rather than
                   splitting the pair. */
                at = Math.max(at, last);
                last = at;
                if (cards[at]) cards[at].after(w);
                else contentParent.appendChild(w);
            });
            if (fab) fab.hidden = false;
        }

        function toDesktop() {
            movable.forEach(el => {
                const a = anchors.get(el);
                if (a && a.parentNode) a.parentNode.insertBefore(el, a);
            });
            if (fab) fab.hidden = true;
            closeDrawer();
        }

        function openDrawer() {
            if (!drawer) return;
            drawer.hidden = false;
            requestAnimationFrame(() => drawer.classList.add("open"));
            document.body.classList.add("drawer-open");
        }
        function closeDrawer() {
            if (!drawer) return;
            drawer.classList.remove("open");
            document.body.classList.remove("drawer-open");
            setTimeout(() => { if (!drawer.classList.contains("open")) drawer.hidden = true; }, 300);
        }

        if (fab) {
            fab.addEventListener("click", openDrawer);
            drawer.addEventListener("click", function (e) {
                if (e.target === drawer || e.target.closest(".profile-drawer-close")) closeDrawer();
            });
            document.addEventListener("keydown", function (e) {
                if (e.key === "Escape") closeDrawer();
            });

            /* Collapse the pill back to a circle while scrolling DOWN, expand again
               on the way UP — same rule the sticky navbar uses, so the two pieces of
               floating chrome move together. The label is only hidden visually; the
               aria-label carries the name either way, so the accessible name never
               changes under a screen reader mid-scroll. */
            let fabLastY = window.scrollY;
            window.addEventListener("scroll", function () {
                const y = window.scrollY;
                if (y > fabLastY && y > 200) fab.classList.add("is-collapsed");
                else fab.classList.remove("is-collapsed");
                fabLastY = y;
            }, { passive: true });
        }

        const apply = e => (e.matches ? toMobile() : toDesktop());
        mq.addEventListener("change", apply);
        /* Crossing 768 changes how many cards a row holds, so the promos have to
           be re-placed — but only while we are already in the mobile layout. */
        gridMq.addEventListener("change", () => { if (mq.matches) toMobile(); });
        apply(mq);
    });
})();


/* =========================
   NAV DRAWER — the mobile hamburger menu (assets/includes/header.php)
   Below 992px .navbar-collapse is an off-canvas right-edge drawer over a scrim
   (see responsive.css). Bootstrap's collapse plugin cannot drive it — collapse
   animates height and toggles display between states, which kills a transform
   slide — so the toggler carries no data-bs-* attributes and this runs it.

   `hidden` is not used on the drawer itself: it is kept in the DOM and moved
   with transform/visibility so both the open AND close transitions run.
========================= */
(function () {

    const toggler = document.querySelector(".custom-toggler");
    const drawer = document.getElementById("navbarSupportedContent");
    const backdrop = document.querySelector(".nav-backdrop");
    if (!toggler || !drawer) return;

    const mq = window.matchMedia("(max-width: 991.98px)");

    function setOpen(open) {

        drawer.classList.toggle("open", open);
        document.body.classList.toggle("nav-open", open);
        toggler.setAttribute("aria-expanded", open ? "true" : "false");
        toggler.setAttribute("aria-label", open ? "Close menu" : "Open menu");

        if (backdrop) {
            if (open) backdrop.hidden = false;
            requestAnimationFrame(() => backdrop.classList.toggle("open", open));
            if (!open) setTimeout(() => {
                if (!backdrop.classList.contains("open")) backdrop.hidden = true;
            }, 300);
        }

        /* The bar carries the drawer; letting the scroll handler translate it
           away would take the open drawer with it. */
        if (open) document.querySelector(".sticky-nav")?.classList.remove("nav-hidden");

        /* The drawer is a modal surface below 992px: it covers the page and the
           backdrop swallows clicks behind it. Tab must not be able to walk out
           into content the user cannot see. Only below 992px — above it, the same
           markup is the ordinary desktop nav row and must stay in the page's flow. */
        if (open && mq.matches) {
            /* role goes on and off with the state: left on permanently it would
               tell desktop AT that the ordinary nav row is a dialog. */
            drawer.setAttribute("role", "dialog");
            drawer.setAttribute("aria-modal", "true");
            drawer.setAttribute("aria-label", "Menu");
        } else {
            drawer.removeAttribute("role");
            drawer.removeAttribute("aria-modal");
            drawer.removeAttribute("aria-label");
        }

        if (open && mq.matches) {
            /* Focus the first thing IN the drawer, not the drawer itself: the next
               Tab then continues down the menu instead of restarting at the top.

               AFTER the slide, not during it. The closed drawer is
               visibility:hidden, and focus() is silently dropped on it for as
               long as the visibility transition is still running — two rAFs is
               not enough, the computed value already reads "visible" while the
               call still does nothing and focus stays on the body. So wait out
               the transition, the same 300ms the backdrop above uses.
               transitionend is not used: under prefers-reduced-motion there is
               no transition and it would never fire. */
            setTimeout(() => {
                if (drawer.classList.contains("open")) focusables()[0]?.focus();
            }, 320);
        }
    }

    /* Recomputed per call — the Matches sub-menu expands and collapses, so the
       focusable set is not fixed. :not([hidden]) alone is not enough; an item in
       a collapsed sub-menu has zero size, hence the offsetParent check. */
    function focusables() {
        return Array.from(drawer.querySelectorAll(
            'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )).filter((el) => el.offsetParent !== null);
    }

    toggler.addEventListener("click", function () {
        setOpen(!drawer.classList.contains("open"));
    });

    /* Close and hand focus back to the control that opened the drawer.
       setOpen() alone leaves focus on <body> — the focus trap keeps a keyboard
       user inside while it is open, then drops them at the top of the document
       on the way out, so the next Tab restarts at the skip link.

       NOT used on the link path (the page is navigating away) or on the
       resize-to-desktop path: above 992px Bootstrap hides the toggler, and
       focus() on a display:none element is a no-op that would strand focus. */
    function closeAndRestore() {
        setOpen(false);
        if (mq.matches) toggler.focus();
    }

    /* In the drawer the Matches parent is a disclosure, not a destination —
       its own href duplicates the "All Profiles" child below it. */
    const matches = drawer.querySelector(".dropdown-toggle");
    const matchesItem = matches ? matches.closest(".dropdown") : null;

    drawer.addEventListener("click", function (e) {

        if (mq.matches && matchesItem && e.target.closest(".dropdown-toggle")) {
            e.preventDefault();
            matchesItem.classList.toggle("open");
            matches.setAttribute("aria-expanded", matchesItem.classList.contains("open") ? "true" : "false");
            return;
        }

        /* The close button stays on THIS page, so focus has to go somewhere
           deliberate. A link does not: the page is navigating away. */
        if (e.target.closest(".nav-drawer-close")) {
            closeAndRestore();
            return;
        }

        /* Tapping a destination should not leave the drawer open behind the new
           page on a bfcache restore. */
        if (e.target.closest("a[href]")) setOpen(false);
    });

    if (backdrop) backdrop.addEventListener("click", closeAndRestore);

    document.addEventListener("keydown", function (e) {

        if (!drawer.classList.contains("open")) return;

        /* FOCUS TRAP. Wrap Tab at both ends of the drawer. */
        if (e.key === "Tab" && mq.matches) {
            const items = focusables();
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];

            /* activeElement can be outside the drawer entirely — e.g. the toggler
               that opened it, or the body after a click on the backdrop. */
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

        if (e.key === "Escape") closeAndRestore();
    });

    /* Bootstrap's dropdown plugin owns the Matches menu on desktop (it floats).
       In the drawer it expands in place under our own .open class, so detach
       the plugin below 992px — it is delegated on [data-bs-toggle], so removing
       the attribute is enough — and re-attach above. */
    /* PORTAL THE DRAWER OUT OF THE STICKY BAR (below 992px only).

       The drawer is position:fixed but its markup sits inside .sticky-nav, and
       on scroll down the scroll handler gives that bar .nav-hidden ->
       translateY(-100%). A transform makes the bar a CONTAINING BLOCK, so the
       drawer stops resolving against the viewport and is trapped in the bar.

       setOpen() already guards the OPEN drawer by stripping .nav-hidden. The
       CLOSED drawer was the bug: parked off-screen at translateX(100%), once
       trapped its box (x 390..730 on a 390px phone) became real document
       overflow, Chrome grew the LAYOUT viewport to cover it, and every
       position:fixed element sized to that instead of the screen — which is
       how .mobile-footer rendered 730px wide and walked its tabs off the left
       edge the moment you scrolled. It went unseen for so long because a
       closed drawer is visibility:hidden: still laid out, just invisible.

       CSS cannot fix this (overflow-x:clip on html does NOT stop the layout
       viewport growing), so move the drawer out of the transformed subtree.
       No stylesheet rule of ours couples the drawer to nav ancestry — but
       Bootstrap's own `.navbar-expand-lg .navbar-collapse{display:flex}` does,
       and that is what restores the desktop row, so it must go back at 992px+.
       The backdrop rides along: it is a sibling in the same trapped subtree. */
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
                matches.removeAttribute("data-bs-toggle");
                /* Start expanded when the member is inside Matches, so the
                   current page is visible without a tap. */
                const here = matchesItem.classList.contains("open") ||
                    matches.classList.contains("active");
                matchesItem.classList.toggle("open", here);
                matches.setAttribute("aria-expanded", here ? "true" : "false");
            } else {
                matches.setAttribute("data-bs-toggle", "dropdown");
                matchesItem.classList.remove("open");
                matches.setAttribute("aria-expanded", "false");
            }
        }

        if (!e.matches) setOpen(false);   // resized up to desktop
    }

    mq.addEventListener("change", syncMode);
    syncMode(mq);

})();


/* =========================
   TAB SHEET — the Interests bottom sheet on the mobile tab bar
   The Interests tab is a <button aria-controls="..."> instead of a link: tapping it
   lifts a sheet with the interest filters and the jump-off links. Generic over
   [aria-controls] so a second tab can get a sheet later with markup alone.

   `hidden` is toggled a frame apart from `.open` so the CSS transform transition
   actually runs — a hidden element cannot animate from its closed position.
========================= */
(function () {

    /* DELEGATED on purpose. Binding to the toggle at parse time silently does
       nothing on any page that includes script.php BEFORE footer2.php — the tab
       bar does not exist yet. All 22 pages now order the includes correctly, but
       a document-level listener is immune to the next one that doesn't. */

    function sheetOf(el) {
        return document.getElementById(el.getAttribute("aria-controls"));
    }

    /* The panel rests on top of the tab bar, so it needs the bar's real height —
       which changes with the font size and the safe-area inset on a notched
       phone. Measure it rather than hardcoding; the CSS carries a 90px fallback. */
    function measureBar() {
        const bar = document.querySelector(".mobile-footer");
        if (bar && bar.offsetHeight) {
            document.documentElement.style.setProperty("--tab-bar-h", bar.offsetHeight + "px");
        }
    }
    window.addEventListener("load", measureBar);
    window.addEventListener("resize", measureBar);
    measureBar();

    /* KEEP THE BAR ON SCREEN.
       `position: fixed; bottom: 0` anchors to the LAYOUT viewport. On a phone
       the browser's own URL bar collapses and expands as you scroll, which
       resizes the VISUAL viewport only — so the layout bottom drops below what
       you can see and the tab bar slides away on scroll down, then returns on
       scroll up. That is exactly the top header's behaviour, and this bar must
       never copy it: below 992px it is the only navigation there is.

       So measure the gap between the two viewports and lift the bar by it.
       Desktop and any browser without visualViewport keep a 0 gap and the
       plain bottom: 0. */
    const vv = window.visualViewport;
    function pinBar() {
        const bar = document.querySelector(".mobile-footer");
        if (!bar || !vv) return;
        const gap = document.documentElement.clientHeight - (vv.height + vv.offsetTop);
        /* >1px, so sub-pixel rounding never jitters the bar. */
        bar.style.setProperty("--bar-lift", (gap > 1 ? gap : 0) + "px");
    }
    if (vv) {
        vv.addEventListener("resize", pinBar);
        vv.addEventListener("scroll", pinBar);
        window.addEventListener("load", pinBar);
        pinBar();
    }

    function close(sheet) {
        sheet.classList.remove("open");
        document.body.classList.remove("sheet-open");
        document.querySelectorAll('[aria-controls="' + sheet.id + '"]')
            .forEach(t => t.setAttribute("aria-expanded", "false"));
        setTimeout(() => { if (!sheet.classList.contains("open")) sheet.hidden = true; }, 300);
    }

    document.addEventListener("click", function (e) {

        const toggle = e.target.closest(".mobile-footer .tab-sheet-toggle");
        if (toggle) {
            const sheet = sheetOf(toggle);
            if (!sheet) return;
            if (sheet.classList.contains("open")) {
                close(sheet);
            } else {
                measureBar();
                sheet.hidden = false;
                requestAnimationFrame(() => sheet.classList.add("open"));
                document.body.classList.add("sheet-open");
                toggle.setAttribute("aria-expanded", "true");
            }
            return;
        }

        /* Backdrop tap and the close button both dismiss; a tap on a link inside
           the panel is left alone so it navigates. */
        const sheet = e.target.closest(".tab-sheet");
        if (sheet && (e.target === sheet || e.target.closest(".tab-sheet-close"))) close(sheet);
    });

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        document.querySelectorAll(".tab-sheet.open").forEach(close);
    });
})();



/* =========================
   PAGE BACK BUTTON — the [< Back] control in assets/includes/pagenav.php
   The link's href is the page's PARENT, which is the right destination for a deep
   link, a new tab or a no-JS visitor. But when the visitor actually arrived from
   another page of this site, history.back() is better: it restores their scroll
   position in a long result list, and it does not grow the history stack (so the
   browser's own back button never has to be pressed twice).

   Plain DOM and outside the jQuery ready block, like the preloader: navigation must
   not depend on jQuery having loaded. Same-origin is checked against the referrer —
   an external referrer means there is nothing of ours to go back TO.
========================= */
(function () {
    document.addEventListener("click", function (e) {
        const back = e.target.closest("[data-page-back]");
        if (!back) return;

        /* Let modified clicks (new tab / new window / download) do their normal
           thing — history.back() in a new tab would land on about:blank. */
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        let sameSite = false;
        try {
            sameSite = !!document.referrer &&
                new URL(document.referrer).origin === window.location.origin;
        } catch (err) {
            sameSite = false;
        }

        /* history.length > 1 guards the case where the referrer exists but the entry
           does not (e.g. the tab was opened straight onto this URL). */
        if (sameSite && window.history.length > 1) {
            e.preventDefault();
            window.history.back();
        }
        /* else: fall through and follow the href to the parent page. */
    });
})();
