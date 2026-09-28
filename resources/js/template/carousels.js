/* =============================================================================
   CAROUSELS — Swiper only  (ported from custom.js)
   =============================================================================
   Two rules from the template, both load-bearing:

   1. Init per ELEMENT, never per selector string (.ad-oppam and .match-slider can
      appear twice on one page).
   2. Scope pagination/navigation to the container — a bare '.swiper-pagination'
      resolves to the first one in the document.

   Added for Livewire: an element that already carries a Swiper is skipped (so a
   re-run after a morph is harmless), and every instance is destroyed when the page
   is left, or its autoplay timer would keep running against a detached node.
============================================================================= */

import Swiper from 'swiper/bundle';
import { onPage, prefersReducedMotion } from './lifecycle';

function autoplayOpts(delay) {
    if (prefersReducedMotion) return false;
    return { delay, disableOnInteraction: false, pauseOnMouseEnter: true };
}

function heroOptions(hero) {
    /* Dots are the slide photos, tooltips the slide headings — read off the slides. */
    const slideData = [...hero.querySelectorAll('.swiper-slide')].map((slide) => ({
        src: slide.querySelector('.basement-images img')?.getAttribute('src') ?? '',
        title: slide.querySelector('.basement-details h2')?.textContent.trim() ?? '',
    }));

    const escapeAttr = (value) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

    function animateActive(swiper) {
        hero.querySelectorAll('.basement-details').forEach((el) => el.classList.remove('animate-content'));
        const details = swiper.slides[swiper.activeIndex]?.querySelector('.basement-details');
        if (!details) return;
        // Two frames, so removing and re-adding the class restarts the animation.
        requestAnimationFrame(() => requestAnimationFrame(() => details.classList.add('animate-content')));
    }

    return {
        loop: true,
        slidesPerView: 1,
        speed: 900, // Swiper's 300ms default reads as a snap on a full-bleed hero photo.
        autoplay: autoplayOpts(7000),
        navigation: {
            prevEl: hero.querySelector('.swiper-button-prev'),
            nextEl: hero.querySelector('.swiper-button-next'),
        },
        pagination: {
            el: hero.querySelector('.swiper-pagination'),
            clickable: true,
            renderBullet(index, className) {
                const slide = slideData[index] ?? { src: '', title: '' };
                const title = escapeAttr(slide.title);
                return `<button type="button" class="${className}" data-title="${title}"`
                    + ` aria-label="Go to slide ${index + 1}: ${title}">`
                    + `<img src="${escapeAttr(slide.src)}" alt="" width="55" height="55"></button>`;
            },
        },
        on: {
            init() { animateActive(this); },
            slideChange() { animateActive(this); },
        },
    };
}

const configs = {
    '.basement-carousel': heroOptions,

    /* Viewport breakpoints; the slider lives in the dashboard's col-6 centre column. */
    '.match-slider': () => ({
        loop: true,
        breakpoints: {
            0: { slidesPerView: 2, spaceBetween: 5 },
            768: { slidesPerView: 3, spaceBetween: 5 },
            992: { slidesPerView: 3, spaceBetween: 10 },
        },
    }),

    /* Sponsored ad rail: one at a time, dots, autoplay. */
    '.ad-oppam': (el) => ({
        loop: true,
        spaceBetween: 10,
        slidesPerView: 1,
        autoplay: autoplayOpts(5000),
        pagination: { el: el.querySelector('.swiper-pagination'), clickable: true },
    }),

    /* Success stories rail (profile page): one at a time, no chrome. */
    '.stories': () => ({
        loop: true,
        slidesPerView: 1,
        spaceBetween: 10,
        autoplay: autoplayOpts(5000),
    }),

    /* Testimonials (home): 1 / 2 / 3 / 4 across, dots. */
    '.testimonial-carousel': (el) => ({
        loop: true,
        spaceBetween: 24,
        slidesPerView: 1,
        autoplay: autoplayOpts(5000),
        pagination: { el: el.querySelector('.swiper-pagination'), clickable: true },
        breakpoints: { 576: { slidesPerView: 2 }, 992: { slidesPerView: 3 }, 1200: { slidesPerView: 4 } },
    }),
};

/** Initialise every carousel under `root` that has not been initialised yet. */
export function initCarousels(root = document) {
    const created = [];

    Object.entries(configs).forEach(([selector, options]) => {
        root.querySelectorAll(selector).forEach((el) => {
            if (el.swiper) return;
            created.push(new Swiper(el, options(el)));
        });
    });

    return created;
}

onPage((signal) => {
    const instances = initCarousels();
    signal.addEventListener('abort', () => instances.forEach((swiper) => swiper.destroy(true, false)));
});
