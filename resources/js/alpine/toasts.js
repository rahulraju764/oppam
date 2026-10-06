/* =============================================================================
   toastStack — slide-in toasts (PRD M08 Shared\ToastStack), built in Alpine: no server round trip.
   Listens to the window `toast` event, which both a Livewire `$this->dispatch('toast', title: …)`
   and a live notification (resources/js/realtime.js) send:
     { title, body?, url?, icon?, type? ('info' | 'success' | 'danger') }
   Auto-dismiss after 6 s (paused while hovered or focused); click follows `url` (same-site paths
   only). Text is rendered with x-text — never as HTML.
============================================================================= */

const DISMISS_AFTER_MS = 6000;
const MAX_VISIBLE = 4;

const sameSite = (url) => {
    if (typeof url !== 'string' || url === '') return null;
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.origin === window.location.origin ? parsed.pathname + parsed.search + parsed.hash : null;
    } catch {
        return null;
    }
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStack', () => ({
        toasts: [],
        next: 1,

        add(detail) {
            const data = Array.isArray(detail) ? (detail[0] ?? {}) : (detail ?? {});
            if (typeof data.title !== 'string' || data.title === '') return;

            const toast = {
                id: this.next++,
                title: data.title,
                body: typeof data.body === 'string' ? data.body : '',
                url: sameSite(data.url),
                icon: typeof data.icon === 'string' && /^fa-[a-z0-9-]+$/.test(data.icon) ? data.icon : 'fa-bell',
                type: ['success', 'danger'].includes(data.type) ? data.type : 'info',
                timer: null,
            };

            this.toasts = [...this.toasts, toast].slice(-MAX_VISIBLE);
            this.arm(toast);
        },

        arm(toast) {
            clearTimeout(toast.timer);
            toast.timer = setTimeout(() => this.dismiss(toast.id), DISMISS_AFTER_MS);
        },

        hold(toast) {
            clearTimeout(toast.timer);
        },

        dismiss(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },

        follow(toast) {
            if (!toast.url) return;
            this.dismiss(toast.id);
            if (window.Livewire?.navigate) window.Livewire.navigate(toast.url);
            else window.location.assign(toast.url);
        },
    }));
});
