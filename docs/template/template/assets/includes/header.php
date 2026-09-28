<?php
/* nav_active() lives in auth.php — the mobile tab bar (footer2.php) needs it too. */
require_once __DIR__ . '/auth.php';
?>

<!-- Preloader. Lives here so all 21 pages get it from the one include.
     custom.js removes it on window.load; if custom.js never runs (or fails to load at all),
     the CSS failsafe animation in style.css hides it at 8s, so a JS error can never leave the
     site behind a permanent curtain. -->
<div class="preloader" id="preloader">
  <div class="preloader-inner">

    <div class="preloader-mark">
      <span class="preloader-ring"></span>
      <span class="preloader-ring preloader-ring-2"></span>
      <img src="assets/images/logo/oppam-logo.webp" class="preloader-logo" alt="" width="600" height="301" fetchpriority="high" decoding="async">
    </div>

    <!-- NOT .script-accent. The curtain is up exactly WHILE the webfonts are downloading, so
         Great Vibes has not arrived yet and the browser falls back to its generic `cursive`
         — i.e. Comic Sans. Anything on this screen must look right unstyled. -->
    <p class="preloader-title">Oppam Matrimony</p>
    <p class="preloader-tag">Two hearts, one journey</p>

    <div class="preloader-bar"><span></span></div>

    <p class="visually-hidden" role="status">Loading Oppam Matrimony…</p>
  </div>
</div>

<!-- Skip link. First focusable thing on the page, so a keyboard or screen-reader
     user does not tab through the navbar, the page-nav bar and (on mobile) five
     tab-bar controls before reaching content. Every page wraps its content in
     <main id="main"> — add the wrapper when you add a page, or this lands nowhere.
     Visually hidden until focused; see .skip-link in style.css. -->
<a class="skip-link" href="#main">Skip to main content</a>

<header>

  <section class="nav-w100 sticky-nav">

    <!-- The chrome stays in a capped container even though page content is
         full-bleed — see --container-chrome in style.css. -->
    <div class="container is-chrome">

      <nav class="navbar navbar-expand-lg <?php ee(is_logged_in() ? 'navbar-member' : 'navbar-public'); ?>">

        <!-- LOGO -->

        <a class="navbar-brand nav-logo" href="<?php ee(home_url()); ?>">

          <img src="assets/images/logo/oppam-logo.webp" class="img-fluid" alt="Oppam Matrimony" width="600" height="301" fetchpriority="high" decoding="async">

        </a>


        <?php if (is_logged_in()): ?>

          <!-- MOBILE HEADER (member) -->
          <!-- TODO(backend): name / member ID / membership tier below are hardcoded demo data. -->

          <div class="mobile-header d-lg-none">

            <!-- LEFT -->

            <a href="my-profile" class="mobile-left">

              <img src="assets/images/home/profile2.webp" alt="" width="600" height="600" fetchpriority="high" decoding="async">

              <div class="mobile-user-info">
                <h4>Sally Roberts</h4>
                <span class="member-badge">Free Member</span>
              </div>

            </a>


            <!-- RIGHT -->

            <div class="mobile-right">

              <!-- package.php belongs to no tab-bar section, so on mobile it used to
                   light nothing anywhere. This button is its section marker: it is the
                   route TO the page, so it is the honest thing to mark as current
                   rather than borrowing a tab that means something else. -->
              <a href="package"
                 class="mobile-upgrade<?php ee(nav_active('package.php') ? ' is-current' : ''); ?>"
                 <?php ee(nav_active('package.php') ? 'aria-current="page"' : ''); ?>
                 aria-label="Upgrade membership">
                <i class="fa fa-diamond" aria-hidden="true"></i>
              </a>

              <!-- An anchor again. It was a DISABLED BUTTON for as long as there was no
                   notifications page — an anchor advertising a destination that did not
                   exist, with a badge saying "3", was the worse of the two. notifications.php
                   now exists. Keep it in step with .nav-bell below: same destination, same
                   count, two surfaces.
                   TODO(backend): the "3" is hardcoded in BOTH bells. Drive it from the
                   session and change the two together. -->
              <a href="notifications"
                 class="mobile-bell<?php ee(nav_active('notifications.php') ? ' is-current' : ''); ?>"
                 <?php ee(nav_active('notifications.php') ? 'aria-current="page"' : ''); ?>
                 aria-label="Notifications (3 unread)">

                <i class="fa fa-bell-o" aria-hidden="true"></i>

                <span class="mobile-bell-count" aria-hidden="true">3</span>

              </a>

            </div>

          </div>

        <?php endif; ?>


        <!-- TOGGLER -->
        <!-- NOT data-bs-toggle="collapse" any more. Below 992px the menu is an
             off-canvas drawer (see .navbar-collapse in responsive.css), which
             slides on transform — Bootstrap's collapse animates HEIGHT and sets
             display:none between states, so the slide-out could never run. The
             drawer is driven by the NAV DRAWER block in custom.js instead; at
             992px+ Bootstrap's own .navbar-expand-lg rule still forces the row
             visible, so the desktop nav is unaffected. -->

        <button class="navbar-toggler custom-toggler"
          type="button"
          aria-controls="navbarSupportedContent"
          aria-expanded="false"
          aria-label="Open menu">

          <span></span>
          <span></span>
          <span></span>

        </button>


        <!-- MENU -->
        <!-- No `collapse` class: that would hide the drawer outright on mobile. -->

        <div class="navbar-collapse" id="navbarSupportedContent">

          <!-- Drawer chrome. Only ever visible below 992px — the desktop rule
               hides it, so the horizontal nav row is unchanged. -->
          <div class="nav-drawer-head d-lg-none">

            <img src="assets/images/logo/oppam-logo.webp" class="nav-drawer-logo" alt="Oppam Matrimony" width="600" height="301" fetchpriority="high" decoding="async">

            <button type="button" class="nav-drawer-close" aria-label="Close menu">
              <i class="fa fa-times" aria-hidden="true"></i>
            </button>

          </div>

          <?php if (is_logged_in()): ?>

            <!-- Identity card at the top of the drawer. The .mobile-header behind
                 the open drawer is covered by it, so this does not repeat it. -->
            <!-- TODO(backend): hardcoded demo name / ID / tier. -->
            <a href="my-profile" class="nav-drawer-user d-lg-none">

              <img src="assets/images/home/profile2.webp" alt="" width="600" height="600" fetchpriority="high" decoding="async">

              <span class="nav-drawer-user-info">
                <strong>Sally Roberts</strong>
                <span class="profile-id">VIS446178</span>
              </span>

              <span class="chip">Free</span>

            </a>

          <?php endif; ?>

          <p class="nav-drawer-label d-lg-none">Menu</p>

          <ul class="navbar-nav ms-auto menus">

            <?php if (is_logged_in()): ?>

              <!-- ===== MEMBER NAV ===== -->

              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('dashboard.php') ? 'active' : ''); ?>"
                   href="<?php ee(home_url()); ?>"
                   <?php ee(nav_active('dashboard.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-home" aria-hidden="true"></i>
                  <span>Home</span>
                </a>

              </li>


              <!-- MATCHES — a dropdown, because Daily Matches previously had NO header
                   presence at all (it was reachable only from the dashboard and footer).
                   Bootstrap's dropdown toggles on CLICK, so it works on touch; a
                   hover-only menu would be unusable on mobile. -->
              <li class="nav-item dropdown">

                <!-- Keep this list in step with $in_matches in footer2.php — the two
                     nav surfaces must agree on what "inside Matches" means. -->
                <?php $in_matches = nav_active('all-profiles.php', 'my-matches.php', 'daily-matches.php', 'single-profile.php'); ?>

                <!-- A BUTTON, not a link. It was <a href="all-profiles"> with
                     role="button" — but Bootstrap swallows the click on desktop and the
                     drawer preventDefaults it on mobile, so the href was unreachable at
                     every width while still promising a destination. It is purely a
                     disclosure; "All Profiles" below is the destination it duplicated. -->
                <button type="button"
                   class="nav-link head-link dropdown-toggle <?php ee($in_matches ? 'active' : ''); ?>"
                   id="navMatches"
                   data-bs-toggle="dropdown"
                   aria-controls="navMatchesMenu"
                   aria-expanded="false">
                  <i class="fa fa-user" aria-hidden="true"></i>
                  <span>Matches</span>
                </button>

                <ul class="dropdown-menu nav-dropdown" id="navMatchesMenu" aria-labelledby="navMatches">
                  <li>
                    <a class="dropdown-item <?php ee(nav_active('all-profiles.php') ? 'active' : ''); ?>" href="all-profiles">
                      <i class="fa fa-users" aria-hidden="true"></i> All Profiles
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item <?php ee(nav_active('my-matches.php') ? 'active' : ''); ?>" href="my-matches">
                      <i class="fa fa-heart" aria-hidden="true"></i> My Matches
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item <?php ee(nav_active('daily-matches.php') ? 'active' : ''); ?>" href="daily-matches">
                      <i class="fa fa-bolt" aria-hidden="true"></i> Daily Matches
                    </a>
                  </li>
                </ul>

              </li>


              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('interest.php') ? 'active' : ''); ?>"
                   href="interest"
                   <?php ee(nav_active('interest.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-address-book" aria-hidden="true"></i>
                  <span>Interests</span>
                </a>

              </li>


              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('search.php') ? 'active' : ''); ?>"
                   href="search"
                   <?php ee(nav_active('search.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-search" aria-hidden="true"></i>
                  <span>Search</span>
                </a>

              </li>


              <!-- Membership had NO desktop nav item — only a mobile icon and a button
                   buried in the avatar dropdown. Contact/FAQ moved into that dropdown
                   (they're support links, not primary member destinations) to make room. -->
              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('package.php') ? 'active' : ''); ?>"
                   href="package"
                   <?php ee(nav_active('package.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-diamond" aria-hidden="true"></i>
                  <span>Membership</span>
                </a>

              </li>


              <!-- NOTIFICATION -->
              <!-- Desktop only (d-none d-lg-flex): below 992px the bell already exists in
                   .mobile-header above, so rendering it in the drawer too would be the same
                   control twice on one screen.
                   Icon-only — it sits next to the avatar as chrome, not as a labelled
                   destination like Home/Matches; the label is on the aria-label.
                   TODO(backend): the count is hardcoded demo data in BOTH bells. Drive it
                   from the session — keep it in step with .mobile-bell in the mobile header. -->

              <li class="nav-item d-none d-lg-flex">

                <a href="notifications"
                   class="nav-bell<?php ee(nav_active('notifications.php') ? ' is-current' : ''); ?>"
                   <?php ee(nav_active('notifications.php') ? 'aria-current="page"' : ''); ?>
                   aria-label="Notifications (3 unread)">

                  <i class="fa fa-bell-o" aria-hidden="true"></i>

                  <span class="nav-bell-count" aria-hidden="true">3</span>

                </a>

              </li>


              <!-- PROFILE -->
              <!-- TODO(backend): name / member ID / membership tier below are hardcoded demo data. -->

              <li class="nav-item profile-dropdown">

                <button type="button" class="profile-toggle" aria-expanded="false" aria-haspopup="true">

                  <img src="assets/images/home/profile2.webp" alt="" width="600" height="600" fetchpriority="high" decoding="async">

                  <span class="visually-hidden">Open account menu</span>

                  <!-- TODO(backend): hardcoded demo name / tier. -->
                  <span class="profile-toggle-meta" aria-hidden="true">
                    <strong>Sally Roberts</strong>
                    <span class="member-badge">Free</span>
                  </span>

                  <i class="fa fa-angle-down" aria-hidden="true"></i>

                </button>

                <div class="profile-dropdown-content">

                  <div class="profile-top">

                    <a href="my-profile">
                      <img src="assets/images/home/profile1.webp" alt="" width="600" height="600" loading="lazy" decoding="async">
                    </a>

                    <div class="profile-content">
                      <h4>Sally Roberts</h4>
                      <p class="profile-id">VIS446178</p>
                      <span>Free Member</span>
                    </div>

                  </div>


                  <!-- MEMBERSHIP -->

                  <div class="membership-box">

                    <p>
                      Upgrade membership to call/chat with matches
                      and unlock premium features.
                    </p>

                    <a href="package" class="upgrade-btn">
                      Upgrade Now
                    </a>

                  </div>


                  <!-- LINKS -->

                  <div class="profile-links">

                    <a href="my-profile" class="<?php ee(nav_active('my-profile.php') ? 'active' : ''); ?>"
                       <?php ee(nav_active('my-profile.php') ? 'aria-current="page"' : ''); ?>>
                      <i class="fa fa-user-o" aria-hidden="true"></i>
                      My Profile
                    </a>

                    <!-- The wizard steps are reached THROUGH my-profile.php, not from here.
                         Its per-section Edit links are the single route into the forms, so a
                         member always sees their current values before changing them. These
                         used to deep-link to profile-creation.php (step 1) and partner.php
                         (step 4), which dropped members into the signup flow mid-stream.
                         Stays highlighted while the member is inside any wizard step. -->
                    <a href="my-profile" class="<?php ee(nav_active('profile-creation.php', 'education.php', 'family.php', 'partner.php', 'contact-details.php', 'profile-photos.php') ? 'active' : ''); ?>"
                       <?php ee(nav_active('profile-creation.php', 'education.php', 'family.php', 'partner.php', 'contact-details.php', 'profile-photos.php') ? 'aria-current="page"' : ''); ?>>
                      <i class="fa fa-pencil" aria-hidden="true"></i>
                      Edit Profile &amp; Preferences
                    </a>

                    <!-- Messages sits with the member's own destinations, above the support
                         links. It is NOT in the mobile tab bar: that bar's fifth slot has
                         already churned Messages -> Profile -> All Profiles -> Profile once,
                         and Profile has the stronger claim (see footer2.php). On mobile this
                         entry in the drawer's account block is the route. -->
                    <a href="messages" class="<?php ee(nav_active('messages.php') ? 'active' : ''); ?>"
                       <?php ee(nav_active('messages.php') ? 'aria-current="page"' : ''); ?>>
                      <i class="fa fa-envelope-o" aria-hidden="true"></i>
                      Messages
                    </a>

                    <!-- Support links. They used to sit in the primary nav — Contact held a
                         top-level slot while Daily Matches and Membership had none. -->
                    <a href="package#faq" class="<?php ee(nav_active('package.php') ? 'active' : ''); ?>"
                       <?php ee(nav_active('package.php') ? 'aria-current="page"' : ''); ?>>
                      <i class="fa fa-question-circle-o" aria-hidden="true"></i>
                      Packages &amp; FAQ
                    </a>

                    <a href="contact" class="<?php ee(nav_active('contact.php') ? 'active' : ''); ?>"
                       <?php ee(nav_active('contact.php') ? 'aria-current="page"' : ''); ?>>
                      <i class="fa fa-phone" aria-hidden="true"></i>
                      Contact
                    </a>

                    <!-- Privacy was reachable on mobile only through the footer, at the very
                         bottom of a long page. It is the one member page that belongs to no
                         tab-bar section, so with no entry here nothing in the mobile chrome
                         acknowledged it and no nav surface could ever show it as current.
                         It sits with the other support links rather than in the tab bar —
                         a legal page does not earn one of five thumb slots. -->
                    <a href="privacy" class="<?php ee(nav_active('privacy.php') ? 'active' : ''); ?>"
                       <?php ee(nav_active('privacy.php') ? 'aria-current="page"' : ''); ?>>
                      <i class="fa fa-shield" aria-hidden="true"></i>
                      Privacy Policy
                    </a>

                    <!-- TODO(backend): destroy the session here, then redirect to index.php. -->
                    <a href="login" class="profile-logout">
                      <i class="fa fa-sign-out" aria-hidden="true"></i>
                      Logout
                    </a>

                  </div>

                </div>

              </li>

            <?php else: ?>

              <!-- ===== PUBLIC NAV (logged out) ===== -->

              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('index.php') ? 'active' : ''); ?>"
                   href="./"
                   <?php ee(nav_active('index.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-home" aria-hidden="true"></i>
                  <span>Home</span>
                </a>

              </li>


              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('package.php') ? 'active' : ''); ?>"
                   href="package"
                   <?php ee(nav_active('package.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-diamond" aria-hidden="true"></i>
                  <span>Packages &amp; FAQ</span>
                </a>

              </li>


              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('contact.php') ? 'active' : ''); ?>"
                   href="contact"
                   <?php ee(nav_active('contact.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-phone" aria-hidden="true"></i>
                  <span>Contact</span>
                </a>

              </li>


              <li class="nav-item">

                <a class="nav-link head-link <?php ee(nav_active('login.php') ? 'active' : ''); ?>"
                   href="login"
                   <?php ee(nav_active('login.php') ? 'aria-current="page"' : ''); ?>>
                  <i class="fa fa-sign-in" aria-hidden="true"></i>
                  <span>Login</span>
                </a>

              </li>


              <li class="nav-item nav-cta">

                <a href="register" class="upgrade-btn">
                  Register Free
                </a>

              </li>

            <?php endif; ?>

          </ul>

        </div>

      </nav>

    </div>

    <?php
    /* Back / prev / next bar. Rendered from HERE rather than from each page so it is
       site-wide chrome like the navbar itself — page_nav() renders nothing for a page
       that is not in its map (index.php and dashboard.php are the two home pages and
       have no parent), so no page needs to opt out.

       It sits INSIDE .sticky-nav on purpose: Back and Next are wanted most on a long
       page, which is exactly where they used to be scrolled off the top and out of
       reach. In here they inherit the bar's whole behaviour for free — sticky at the
       top, hidden on scroll down, revealed on scroll up — instead of needing a second
       sticky element stacking against the first. Nothing else moves with it: the
       drawer and its backdrop are still portalled out to <body> below 992px. */
    require_once __DIR__ . '/pagenav.php';
    page_nav();
    ?>

    <!-- Drawer backdrop. It is authored here, but it does NOT stay here: below
         992px custom.js moves it (and the drawer) out to <body> — see "PORTAL
         THE DRAWER" in the NAV DRAWER block. Do not "simplify" that away on the
         grounds that the bar only transforms in its .nav-hidden state; that
         holds for the OPEN drawer only, and it was the CLOSED one, parked
         off-screen inside a transformed .sticky-nav, that grew the layout
         viewport and broke the mobile tab bar on scroll. -->
    <div class="nav-backdrop d-lg-none" hidden></div>

  </section>

</header>
