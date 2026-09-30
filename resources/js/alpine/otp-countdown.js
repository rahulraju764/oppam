/* =============================================================================
   otpCountdown(seconds) — the "Resend code in 0:27" timer on the OTP screens (M01).
   Pure display: the server enforces the real resend gap (SendOtp). Restarts when a
   Livewire component dispatches `otp-resent`, reading the fresh value from $wire.resendIn.
============================================================================= */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('otpCountdown', (seconds = 30) => ({
        left: seconds,
        timer: null,

        init() {
            this.start(this.left);
        },

        destroy() {
            clearInterval(this.timer);
        },

        restart() {
            this.start(Number(this.$wire?.resendIn ?? 30));
        },

        start(value) {
            clearInterval(this.timer);
            this.left = Math.max(0, Number(value) || 0);
            if (this.left === 0) return;
            this.timer = setInterval(() => {
                this.left = Math.max(0, this.left - 1);
                if (this.left === 0) clearInterval(this.timer);
            }, 1000);
        },

        get label() {
            const m = Math.floor(this.left / 60);
            const s = String(this.left % 60).padStart(2, '0');
            return `${m}:${s}`;
        },
    }));
});
