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

        init() {
            this.$nextTick(() => {
                this.instances = initCarousels(this.$el);
            });
        },

        destroy() {
            this.instances.forEach((swiper) => swiper.destroy(true, false));
            this.instances = [];
        },
    }));
});
