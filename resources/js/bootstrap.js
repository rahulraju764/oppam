import axios from 'axios';

import { startEcho } from './echo';
import { startRealtime } from './realtime';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/*
 * Real-time (PRD §9): Echo connects only on pages that opt in with
 * <meta name="oppam-realtime" content="on"> (the member layout, for a signed-in member). Started
 * synchronously so Livewire's echo listeners find window.Echo when components boot.
 */
startRealtime(startEcho());
