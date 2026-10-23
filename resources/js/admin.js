/* =============================================================================
   Admin panel bundle (admin.oppam.in). Livewire injects Alpine; Bootstrap JS is only needed for
   the few Bootstrap widgets (dropdowns, collapse).

   Real-time (PRD §9.3, §11.0, P3.5): for a signed-in admin the layout carries
   <meta name="oppam-realtime" content="on">, so Echo connects (auth: this host's
   /broadcasting/auth, admin guard) — started synchronously, before Livewire boots, so components'
   `echo-private:` listeners find window.Echo. Two Alpine stores:
     $store.adminLive.status     'off' | 'connecting' | 'up' | 'down'
     $store.adminQueues          live waiting counts by queue key (sidebar badges, cards)
   `admin.queues`    (.queue.count)     → adminQueues, only if the admin may join it
                     (.case.urgent)     → an urgent toast linking to the P0 safety case (P6.2)
   `admin.dashboard` (.dashboard.tick)  → window event `dashboard-tick` { metric, value }
============================================================================= */

import * as bootstrap from 'bootstrap';

import { startEcho } from './echo';

import './alpine/master-sort';
import './alpine/photo-moderation';
import './alpine/admin-chart';
import './alpine/pdf-document';

window.bootstrap = bootstrap;

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? null;
const echo = startEcho();

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.store('adminLive', { status: echo ? 'connecting' : 'off' });

    Alpine.store('adminQueues', {
        counts: {},
        set(queue, count) {
            if (typeof queue !== 'string' || !Number.isFinite(Number(count))) return;
            this.counts = { ...this.counts, [queue]: Math.max(0, Number(count)) };
        },
        // The number to show: the live one once an event arrived, else what the page was rendered with.
        get(queue, rendered) {
            return queue in this.counts ? this.counts[queue] : rendered;
        },
    });
});

document.addEventListener('alpine:initialized', () => {
    if (!echo) return;

    const live = window.Alpine.store('adminLive');
    const connection = echo.connector?.pusher?.connection;

    if (connection) {
        if (connection.state === 'connected') live.status = 'up';
        connection.bind('state_change', ({ current }) => {
            if (current === 'connected') live.status = 'up';
            else if (['unavailable', 'failed', 'disconnected'].includes(current)) live.status = 'down';
        });
    }

    if (meta('oppam-admin-queues') === 'on') {
        echo.private('admin.queues')
            .listen('.queue.count', (event) => {
                window.Alpine.store('adminQueues').set(event?.queue, event?.count);
            })
            // A10 (P6.2): a case became P0 — an urgent toast linking to it, on every open admin page.
            .listen('.case.urgent', (event) => {
                const code = typeof event?.case === 'string' && /^CASE[0-9]+$/.test(event.case) ? event.case : null;
                if (!code || meta('oppam-urgent-cases') !== 'on') return;   // only admins who can open the case
                window.dispatchEvent(new CustomEvent('toast', {
                    detail: { type: 'danger', sticky: true, message: `${meta('oppam-urgent-case-label') ?? 'Urgent safety case'} ${code}`, url: `/safety/cases/${code}` },
                }));
            });
    }

    if (meta('oppam-admin-dashboard') === 'on') {
        echo.private('admin.dashboard').listen('.dashboard.tick', (event) => {
            window.dispatchEvent(new CustomEvent('dashboard-tick', { detail: { metric: event?.metric, value: event?.value } }));
        });
    }
}, { once: true });
