/* =============================================================================
   countdown(seconds) — "Expires in 5h 12m" on the daily-matches batch (M05 / F06). Pure display:
   the server decides which batch is shown; this only ticks down the seconds it was given.
============================================================================= */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('countdown', (seconds = 0) => ({
        left: Math.max(0, Number(seconds) || 0),
        timer: null,

        init() {
            this.timer = setInterval(() => {
                this.left = Math.max(0, this.left - 1);
                if (this.left === 0) clearInterval(this.timer);
            }, 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        get label() {
            const h = Math.floor(this.left / 3600);
            const m = Math.floor((this.left % 3600) / 60);
            const s = String(this.left % 60).padStart(2, '0');

            return h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}:${s}`;
        },
    }));
});
