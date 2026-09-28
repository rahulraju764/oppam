/* =============================================================================
   COUNTERS + COUNTDOWN  (ported from custom.js)
   =============================================================================
   .counter[data-target] counts up once when half visible (home "counter" band).
   .time-card h4 is the offer countdown. Under prefers-reduced-motion the counters
   show their final value straight away.
============================================================================= */

import { onPage, prefersReducedMotion } from './lifecycle';

function animateCounter(counter) {
    const target = Number(counter.dataset.target) || 0;

    if (prefersReducedMotion) {
        counter.innerText = target;
        return;
    }

    let count = 0;
    const increment = target / 150;

    const update = () => {
        count += increment;
        if (count < target) {
            counter.innerText = Math.ceil(count);
            requestAnimationFrame(update);
        } else {
            counter.innerText = target;
        }
    };

    update();
}

onPage((signal) => {
    const counters = document.querySelectorAll('.counter');

    if (counters.length) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                animateCounter(entry.target);
                observer.unobserve(entry.target); // run only once
            });
        }, { threshold: 0.5 });

        counters.forEach((counter) => observer.observe(counter));
        signal.addEventListener('abort', () => observer.disconnect());
    }

    const timer = document.querySelector('.time-card h4');
    if (!timer) return;

    let totalSeconds = 10 * 60 * 60;
    const pad = (n) => String(n).padStart(2, '0');

    const tick = () => {
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        timer.textContent = `${pad(hours)}h : ${pad(minutes)}m : ${pad(totalSeconds % 60)}s`;
        if (totalSeconds > 0) totalSeconds--;
    };

    tick();
    const interval = setInterval(tick, 1000);
    signal.addEventListener('abort', () => clearInterval(interval));
});
