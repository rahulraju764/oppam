/* =============================================================================
   CHAT (PRD M07, §9.5, F03) — client behaviour only; the server decides who may write.

   chatThread(maxLength, texts, channel, myCode) — the open thread's composer and scroll (texts:
   translated { notSent, tooLong } from the view; channel: "chat.{id}"; myCode: the member's code):
     - a new message on the thread's channel is drawn AT ONCE from the live payload (text only,
       x-text) — the server re-render that follows replaces it (same data-client-id). This is what
       keeps "sent → on the other screen" under a second (M07 acceptance).
     - submit(): the message shows AT ONCE as a grey bubble with a fresh client id, then
       $wire.send(clientId, text). The re-render brings the stored message (it carries the same
       data-client-id) and the bubble is dropped. A refusal ('chat-send-failed') marks the bubble
       "not sent" with Retry — the same client id, so a retry never stores it twice.
     - stays scrolled to the newest message while the member is at the bottom.
     - a message from the other member ('chat-message-arrived') is marked read when the tab is in
       view, otherwise as soon as it comes back into view.
     - polling fallback (no socket, flag on): re-read every 10 s (PRD §9.7.2).
   chatBadge — the header badge: tab title count, and a toast for a new message unless that
     thread is open on screen.
============================================================================= */

const POLL_EVERY_MS = 10_000;
const NEAR_BOTTOM_PX = 120;

const uuid = () => {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    // RFC 4122 v4 from getRandomValues (older browsers / non-secure contexts).
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

const detailOf = (event) => (Array.isArray(event.detail) ? (event.detail[0] ?? {}) : (event.detail ?? {}));

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.data('chatThread', (maxLength = 2000, texts = {}, channel = '', myCode = '') => ({
        body: '',
        pending: [],
        live: [],
        echoChannel: null,
        missedWhileHidden: false,
        polling: null,
        observer: null,
        stickToBottom: true,

        init() {
            this.scrollToBottom();

            const scroll = this.$refs.scroll;
            scroll.addEventListener('scroll', () => {
                this.stickToBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < NEAR_BOTTOM_PX;
            }, { passive: true });

            // Livewire re-renders the messages; keep the newest in view and drop confirmed bubbles.
            this.observer = new MutationObserver(() => {
                this.prune();
                if (this.stickToBottom) this.scrollToBottom();
            });
            this.observer.observe(scroll, { childList: true, subtree: true });

            this.onFailed = (event) => this.fail(detailOf(event));
            this.onArrived = () => this.arrived();
            this.onVisible = () => {
                if (document.visibilityState === 'visible' && this.missedWhileHidden) {
                    this.missedWhileHidden = false;
                    this.$wire.markRead();
                }
            };
            window.addEventListener('chat-send-failed', this.onFailed);
            window.addEventListener('chat-message-arrived', this.onArrived);
            document.addEventListener('visibilitychange', this.onVisible);

            // The same channel Livewire follows for this thread; one more listener on it.
            if (window.Echo && channel !== '') {
                this.onLive = (event) => this.incoming(event);
                this.echoChannel = window.Echo.private(channel);
                this.echoChannel.listen('.message.sent', this.onLive);
            }
        },

        destroy() {
            this.observer?.disconnect();
            clearInterval(this.polling);
            window.removeEventListener('chat-send-failed', this.onFailed);
            window.removeEventListener('chat-message-arrived', this.onArrived);
            document.removeEventListener('visibilitychange', this.onVisible);
            this.echoChannel?.stopListening('.message.sent', this.onLive);
        },

        incoming(event) {
            if (typeof event?.client_id !== 'string' || typeof event?.body !== 'string') return;
            const known = (id) => this.pending.some((p) => p.clientId === id) || this.live.some((l) => l.clientId === id)
                || this.$refs.scroll?.querySelector(`[data-client-id="${CSS.escape(id)}"]`);
            if (known(event.client_id)) return;

            const at = new Date(event.created_at);
            this.live.push({
                clientId: event.client_id,
                text: event.body,
                mine: event.sender_code === myCode,
                time: Number.isNaN(at.getTime()) ? '' : at.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' }),
            });
        },

        submit() {
            const text = this.body.trim();
            if (text === '') return;
            if (text.length > maxLength) {
                this.pending.push({ clientId: uuid(), text, failed: true, error: texts.tooLong ?? '' });
                return;
            }

            const item = { clientId: uuid(), text, failed: false, error: '' };
            this.pending.push(item);
            this.body = '';
            this.stickToBottom = true;
            this.$nextTick(() => this.scrollToBottom());
            this.deliver(item);
        },

        retry(item) {
            item.failed = false;
            item.error = '';
            this.deliver(item);
        },

        deliver(item) {
            this.$wire.send(item.clientId, item.text).then(() => this.$nextTick(() => this.prune()));
        },

        fail({ clientId, message }) {
            const item = this.pending.find((p) => p.clientId === clientId);
            if (!item) return;
            item.failed = true;
            item.error = typeof message === 'string' && message !== '' ? message : (texts.notSent ?? '');
        },

        prune() {
            const scroll = this.$refs.scroll;
            if (!scroll || (this.pending.length === 0 && this.live.length === 0)) return;
            // Only server-rendered messages carry data-client-id (the Alpine bubbles don't).
            const rendered = (id) => scroll.querySelector(`[data-client-id="${CSS.escape(id)}"]`) !== null;
            const left = this.pending.filter((p) => p.failed || !rendered(p.clientId));
            const liveLeft = this.live.filter((l) => !rendered(l.clientId));
            // Only replace a list when a bubble was confirmed: the observer sees our own renders too.
            if (left.length !== this.pending.length) this.pending = left;
            if (liveLeft.length !== this.live.length) this.live = liveLeft;
        },

        arrived() {
            if (document.visibilityState === 'visible') this.$wire.markRead();
            else this.missedWhileHidden = true;
        },

        scrollToBottom() {
            const scroll = this.$refs.scroll;
            if (scroll) scroll.scrollTop = scroll.scrollHeight;
        },

        startPolling() {
            this.polling ??= setInterval(() => this.$wire.$refresh(), POLL_EVERY_MS);
        },
    }));

    Alpine.data('chatBadge', () => ({
        title(unread) {
            Alpine.store('tabTitle')?.set('chat', unread);
        },

        arrived(detail) {
            const data = Array.isArray(detail) ? (detail[0] ?? {}) : (detail ?? {});
            if (typeof data.code !== 'string') return;
            // The thread is open on screen: the message is already in view, no toast.
            if (document.querySelector(`[data-chat-thread="${CSS.escape(data.code)}"]`)) return;

            window.dispatchEvent(new CustomEvent('toast', {
                detail: { title: data.title, body: data.body, url: data.url, icon: 'fa-envelope-o' },
            }));
        },
    }));
});
