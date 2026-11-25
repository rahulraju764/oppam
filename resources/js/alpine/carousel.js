/* =============================================================================
   carousel — starts the template's Swiper carousels inside an element that Livewire renders
   after the page has loaded (a #[Lazy] dashboard strip), which the page-level init in
   template/carousels.js never sees. Destroyed with the element.

     <div x-data="carousel"> … <div class="swiper match-slider">…</div> … </div>
============================================================================= */

import { initCarousels } from '../template/carousels';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('carousel', () => ({
        instances: [],
        gone: false,

        init() {
            this.$nextTick(() => {
                // initCarousels loads Swiper on first use (P9.3c), so it resolves later: the element may be gone by then.
                initCarousels(this.$el).then((instances) => {
                    if (this.gone) {
                        instances.forEach((swiper) => swiper.destroy(true, false));
                        return;
                    }
                    this.instances = instances;
                }).catch((error) => console.error('Carousel could not load:', error));
            });
        },

        destroy() {
            this.gone = true;
            this.instances.forEach((swiper) => swiper.destroy(true, false));
            this.instances = [];
        },
    }));
});
