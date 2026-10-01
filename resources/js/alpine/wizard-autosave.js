/* =============================================================================
   wizardAutosave(idleMs) — the profile wizard's "save after 20 s without typing" (M02).
   Every input/change restarts the timer; when it fires, the Livewire component's
   autosave() keeps whatever is valid so far (partial rules on the server). Nothing is
   decided here: the server validates and saves.
============================================================================= */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('wizardAutosave', (idleMs = 20000) => ({
        timer: null,

        touch() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.$wire.autosave(), idleMs);
        },

        destroy() {
            clearTimeout(this.timer);
        },
    }));
});
