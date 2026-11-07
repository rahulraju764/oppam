/* =============================================================================
   turnstile(siteKey) — Cloudflare Turnstile on the contact form (P8.1b, M12). Loads
   Cloudflare's script once, renders the widget explicitly and hands the single-use token
   to Livewire ($wire.captchaToken). The server verifies it (SubmitContact); this is only
   the widget. `captcha-reset` (after every attempt) asks for a fresh token.
============================================================================= */

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
let loading = null;

function loadTurnstile() {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }
    loading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT;
        script.async = true;
        script.defer = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = () => {
            loading = null;
            reject(new Error('Turnstile failed to load'));
        };
        document.head.appendChild(script);
    });

    return loading;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('turnstile', (siteKey) => ({
        widgetId: null,
        failed: false,

        init() {
            loadTurnstile().then((turnstile) => {
                this.widgetId = turnstile.render(this.$refs.widget, {
                    sitekey: siteKey,
                    callback: (token) => this.$wire.set('captchaToken', token, false),
                    'expired-callback': () => this.$wire.set('captchaToken', '', false),
                    'error-callback': () => this.$wire.set('captchaToken', '', false),
                });
            }).catch(() => {
                this.failed = true;
            });
        },

        reset() {
            if (this.widgetId !== null && window.turnstile) {
                window.turnstile.reset(this.widgetId);
            }
        },

        destroy() {
            if (this.widgetId !== null && window.turnstile) {
                window.turnstile.remove(this.widgetId);
            }
        },
    }));
});
