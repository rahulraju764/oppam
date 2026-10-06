/* =============================================================================
   REAL-TIME CHROME (PRD §9.6 / §9.7) — once per full page load; survives wire:navigate.

   Alpine stores (registered on every page, empty when real-time is off, so markup can always
   read them):
     $store.online   — profile codes currently online (presence.online roster). <x-ui.online-dot
                       :code> reads it; no Livewire round trip.
     $store.realtime — { status: 'off' | 'connecting' | 'connected' | 'reconnecting', fallback,
                         userChannel (the member's own channel is subscribed) }

   Browser / Livewire events:
     realtime-reconnected — the socket came back after a drop: components back-fill from the DB.
     realtime-fallback    — no socket within 10 s and the realtime.polling_fallback flag is on:
                            components may switch to wire:poll (P3.2 bell, P4 chat).

   New notifications on the member's own channel become toasts (<x-ui.toast-stack>).

   The member's own channel carries `.session.revoked` (ForceLogout): the session is already
   revoked on the server, so reloading lets the session middleware sign the tab out. An
   IMPERSONATION-scoped one is obeyed only by a support-session tab.
============================================================================= */

const FALLBACK_AFTER_MS = 10_000;

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? null;

function registerStores() {
    document.addEventListener('alpine:init', () => {
        const Alpine = window.Alpine;

        Alpine.store('online', {
            codes: new Set(),
            has(code) {
                return this.codes.has(code);
            },
            replace(codes) {
                this.codes = new Set(codes);
            },
            add(code) {
                this.codes = new Set([...this.codes, code]);
            },
            remove(code) {
                const next = new Set(this.codes);
                next.delete(code);
                this.codes = next;
            },
        });

        Alpine.store('realtime', { status: 'off', fallback: false, userChannel: false });
    });
}

const store = (name) => window.Alpine?.store(name);

function setStatus(status) {
    const realtime = store('realtime');
    if (realtime) realtime.status = status;
}

function dispatch(name) {
    window.dispatchEvent(new CustomEvent(name));
    window.Livewire?.dispatch(name);
}

function watchConnection(echo) {
    const connection = echo.connector?.pusher?.connection;
    if (!connection) return;

    let everConnected = false;
    setStatus('connecting');

    connection.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            setStatus('connected');
            if (everConnected) dispatch('realtime-reconnected');
            everConnected = true;
        } else if (everConnected && ['connecting', 'unavailable', 'disconnected', 'failed'].includes(current)) {
            setStatus('reconnecting');
        }
    });

    if (meta('oppam-polling-fallback') === 'on') {
        window.setTimeout(() => {
            if (connection.state !== 'connected') {
                const realtime = store('realtime');
                if (realtime) realtime.fallback = true;
                dispatch('realtime-fallback');
            }
        }, FALLBACK_AFTER_MS);
    }
}

function joinPresence(echo) {
    if (meta('oppam-presence') !== 'on') return;

    const code = (member) => (typeof member?.code === 'string' ? member.code : null);

    echo.join('presence.online')
        .here((members) => store('online')?.replace(members.map(code).filter(Boolean)))
        .joining((member) => code(member) && store('online')?.add(code(member)))
        .leaving((member) => code(member) && store('online')?.remove(code(member)));
}

function listenForLogout(echo) {
    const userId = meta('oppam-user');
    if (!userId) return;

    echo.private(`App.Models.User.${userId}`)
        .subscribed(() => {
            const realtime = store('realtime');
            if (realtime) realtime.userChannel = true;
        })
        .listen('.session.revoked', (event) => {
            const forThisTab = event?.scope !== 'IMPERSONATION' || meta('oppam-impersonating') === 'on';
            if (forThisTab) window.location.reload();
        })
        // A new notification (M08): a toast; the bell re-reads its own count (Livewire listener).
        .notification((notification) => {
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { title: notification?.title, body: notification?.body, url: notification?.url, icon: notification?.icon },
            }));
        });
}

export function startRealtime(echo) {
    registerStores();

    if (!echo) return;

    // Alpine starts with Livewire (after this module); its stores exist once it has initialised.
    document.addEventListener('alpine:initialized', () => {
        watchConnection(echo);
        joinPresence(echo);
        listenForLogout(echo);
    }, { once: true });
}
