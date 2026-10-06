/* =============================================================================
   engageToggle — the optimistic like / favourite button (PRD M06 Shared\LikeButton,
   Shared\FavoriteButton). The click shows the new state at once; the Livewire method answers
   with the state the server really left (the server decides — a refusal rolls the button back).
     engageToggle(initial, method, code, syncEvent)
       method     the $wire method to call with the wanted state (returns the real state)
       code       the profile code this button is for
       syncEvent  window event ({ code, on }) other buttons for the same profile listen to, so
                  the same profile shown twice on a page never disagrees — no extra request
============================================================================= */

const detailOf = (event) => (Array.isArray(event.detail) ? (event.detail[0] ?? {}) : (event.detail ?? {}));

document.addEventListener('alpine:init', () => {
    window.Alpine.data('engageToggle', (initial, method, code, syncEvent) => ({
        on: Boolean(initial),
        busy: false,
        sync: null,

        init() {
            this.sync = (event) => {
                const data = detailOf(event);
                if (data.code === code && !this.busy) this.on = Boolean(data.on);
            };
            window.addEventListener(syncEvent, this.sync);
        },

        destroy() {
            window.removeEventListener(syncEvent, this.sync);
        },

        async toggle() {
            if (this.busy) return;

            const wanted = !this.on;
            this.on = wanted;
            this.busy = true;

            try {
                this.on = Boolean(await this.$wire[method](wanted));
            } catch {
                this.on = !wanted;
            } finally {
                this.busy = false;
            }
        },
    }));

    // "It's a match!" and "limit reached" dialogs: filled from a Livewire event, then opened.
    window.Alpine.data('engagementDialog', (modalName) => ({
        info: {},

        show(event) {
            this.info = detailOf(event);
            this.$dispatch('open-modal', { name: modalName });
        },

        close() {
            this.$dispatch('close-modal', { name: modalName });
        },
    }));
});
