import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/*
 * Real-time (PRD §9): Echo connects only on pages that opt in with
 * <meta name="oppam-realtime" content="on"> (authenticated layouts, P3.1). Public pages
 * never open a WebSocket.
 */
if (document.querySelector('meta[name="oppam-realtime"][content="on"]')) {
    import('./echo');
}
