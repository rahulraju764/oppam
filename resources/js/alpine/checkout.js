/* =============================================================================
   razorpayCheckout(scriptUrl, messages) — opens Razorpay Checkout (M10 / F04) with the options the
   SERVER prepared after creating the order (key id, gateway order id, amount). Pure hand-off: the
   amount shown and charged is Razorpay's order amount, which the browser can't change; success only
   navigates to /billing/success, which polls the order until the webhook (or a server-side look-up)
   confirms it — the browser's "paid" is never trusted.
============================================================================= */

let scriptPromise = null;

function loadScript(src) {
    if (window.Razorpay) return Promise.resolve();
    scriptPromise ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            scriptPromise = null;
            script.remove();
            reject(new Error('checkout script failed to load'));
        };
        document.head.appendChild(script);
    });

    return scriptPromise;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('razorpayCheckout', (scriptUrl = '', messages = {}) => ({
        opening: false,
        error: '',

        async open(options, successUrl) {
            if (!options || !successUrl) return;
            this.error = '';
            this.opening = true;

            try {
                await loadScript(scriptUrl);
            } catch {
                this.error = messages.loadFailed ?? 'Payment window could not be opened. Please try again.';
                this.opening = false;
                return;
            }

            const brand = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim();
            const checkout = new window.Razorpay({
                ...options,
                theme: brand ? { color: brand } : undefined,
                handler: () => window.Livewire.navigate(successUrl),
                modal: { ondismiss: () => { this.opening = false; } },
            });

            checkout.on('payment.failed', () => {
                this.error = messages.failed ?? 'The payment did not go through. You can try again.';
            });

            checkout.open();
        },
    }));
});
