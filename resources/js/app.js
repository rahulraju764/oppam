/* =============================================================================
   Member / public / broker bundle (Vite). Replaces the template's script.php.
   Order matters: the preloader goes first so nothing below can leave the curtain up.
============================================================================= */

import './template/preloader';
import './bootstrap';

import * as bootstrap from 'bootstrap';

import { boot } from './template/lifecycle';
import './template/sticky-nav';
import './template/drawer';
import './template/profile-dropdown';
import './template/carousels';
import './template/counters';
import './template/mobile-rail';
import './template/tab-sheet';
import './template/page-back';
import './template/option-buttons';

import './alpine/otp-countdown';
import './alpine/wizard-autosave';
import './alpine/photo-cropper';

window.bootstrap = bootstrap;

boot();
