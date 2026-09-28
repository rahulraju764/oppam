<?php
/* =============================================================================
   PAGE NAV — the back / previous / next bar
   =============================================================================
   Every page except the two home pages (index.php, dashboard.php) sits somewhere
   under one of them, and until now nothing on the page said so: the only way back
   was the browser button, and on the six wizard steps the only way FORWARD was the
   form's submit. This renders one bar, directly under the header:

       [< Back]        Page title        [< Prev]  [Next >]

   - BACK is the honest parent of the page (its section), never "wherever you came
     from" — but custom.js upgrades it to history.back() when the referrer is a page
     of this site, so it matches what the browser button would have done and keeps
     scroll position. The href is the fallback for a deep link / new tab, so the
     control always leads somewhere real.
   - PREV / NEXT walk the page's SIBLINGS (wizard steps, the three Matches pages,
     the support pages). A page with no sibling in a direction simply omits that
     button — they are never rendered disabled.

   Add a page by adding a row to $page_nav_map below. Nothing else needs touching.
   Keep the labels in step with header.php / footer2.php when a destination moves.
   ============================================================================= */

require_once __DIR__ . '/auth.php';

if (!function_exists('page_nav')) {

    /* page => [title, back url, back label, prev url|null, prev label, next url|null, next label]
       'back' of the wizard steps is my-profile.php rather than the previous step:
       the previous step is already the Prev button, and a "back" that repeats it
       leaves the member no way out of the flow. */
    function page_nav_map()
    {
        return [

            /* ---- Auth ---- */
            'login.php' => [
                'title' => 'Login',
                'back'  => ['index.php', 'Home'],
                'next'  => ['register.php', 'Register'],
            ],
            'register.php' => [
                'title' => 'Register Free',
                'back'  => ['index.php', 'Home'],
                'prev'  => ['login.php', 'Login'],
            ],

            /* ---- Profile-creation wizard (6 steps, in order) ---- */
            'profile-creation.php' => [
                'title' => 'Step 1 — Profile Creation',
                'back'  => ['my-profile.php', 'My Profile'],
                'next'  => ['education.php', 'Education'],
            ],
            'education.php' => [
                'title' => 'Step 2 — Education Details',
                'back'  => ['my-profile.php', 'My Profile'],
                'prev'  => ['profile-creation.php', 'Profile'],
                'next'  => ['family.php', 'Family'],
            ],
            'family.php' => [
                'title' => 'Step 3 — Family Details',
                'back'  => ['my-profile.php', 'My Profile'],
                'prev'  => ['education.php', 'Education'],
                'next'  => ['partner.php', 'Partner'],
            ],
            'partner.php' => [
                'title' => 'Step 4 — Partner Preference',
                'back'  => ['my-profile.php', 'My Profile'],
                'prev'  => ['family.php', 'Family'],
                'next'  => ['contact-details.php', 'Contact'],
            ],
            'contact-details.php' => [
                'title' => 'Step 5 — Contact Details',
                'back'  => ['my-profile.php', 'My Profile'],
                'prev'  => ['partner.php', 'Partner'],
                'next'  => ['profile-photos.php', 'Photos'],
            ],
            'profile-photos.php' => [
                'title' => 'Step 6 — Profile Photos',
                'back'  => ['my-profile.php', 'My Profile'],
                'prev'  => ['contact-details.php', 'Contact'],
                'next'  => ['dashboard.php', 'Finish'],
            ],

            /* ---- Browse / match ----
               The three Matches pages are NOT interchangeable (see CLAUDE.md), but
               they are siblings in one section, so prev/next walks them in the same
               order the header dropdown lists them. */
            'all-profiles.php' => [
                'title' => 'All Profiles',
                'back'  => ['dashboard.php', 'Dashboard'],
                'next'  => ['my-matches.php', 'My Matches'],
            ],
            'my-matches.php' => [
                'title' => 'My Matches',
                'back'  => ['dashboard.php', 'Dashboard'],
                'prev'  => ['all-profiles.php', 'All Profiles'],
                'next'  => ['daily-matches.php', 'Daily Matches'],
            ],
            'daily-matches.php' => [
                'title' => 'Daily Matches',
                'back'  => ['dashboard.php', 'Dashboard'],
                'prev'  => ['my-matches.php', 'My Matches'],
            ],

            /* A member profile you opened FROM the directory — so that is the parent.
               This is the ONLY member profile view. details.php was a second copy of
               it (tabbed field grid, "Krishna Priya TS") reachable from one mislabelled
               Success Stories card; its tab panel was merged in here and it was deleted.
               Don't add a second profile page. */
            /* Its prev/next are NOT here: they are the previous / next MEMBER, so
               they depend on ?id=. single-profile.php computes them from
               profiles-data.php and hands them over in $page_nav_prev /
               $page_nav_next before including the header (see below). */
            'single-profile.php' => [
                'title' => 'Member Profile',
                'back'  => ['all-profiles.php', 'All Profiles'],
            ],

            'search.php' => [
                'title' => 'Search',
                'back'  => ['dashboard.php', 'Dashboard'],
            ],
            'interest.php' => [
                'title' => 'Interests',
                'back'  => ['dashboard.php', 'Dashboard'],
            ],
            /* No 'next' into the wizard. It used to point at step 1, but step 1 has
               no 'prev' back here — you could walk in and not out — and the page's
               own per-section Edit links are the real route into the forms, each
               landing on the right step rather than restarting the flow. */
            'my-profile.php' => [
                'title' => 'My Profile',
                'back'  => ['dashboard.php', 'Dashboard'],
            ],
            /* Back only. An inbox has no siblings to walk — the conversations are
               ?thread= on this one page, not separate pages. Same for the
               notification list and its ?type= filters. */
            'messages.php' => [
                'title' => 'Messages',
                'back'  => ['dashboard.php', 'Dashboard'],
            ],
            'notifications.php' => [
                'title' => 'Notifications',
                'back'  => ['dashboard.php', 'Dashboard'],
            ],

            /* ---- Static / support ----
               'back' is home_url(): these four are reachable from both the public and
               the member chrome, so the parent depends on who is looking. */
            /* faq.php is deliberately absent: it is a 301 stub to package.php#faq, so
               a Next pointing at it would bounce the visitor straight back. */
            /* Back only, no prev/next. These three were chained
               package -> contact -> privacy, but they are not a sequence: pricing,
               a contact form and a legal page have nothing to walk between, and
               "Next: Contact" on the pricing page promised a step that does not
               exist. Prev/next is for real orders — the wizard, the Matches trio. */
            'package.php' => [
                'title' => 'Membership Plans',
            ],
            'contact.php' => [
                'title' => 'Contact Us',
            ],
            /* 'prev' back to Terms — the pair walks BOTH ways. A one-way link is the
               exact bug that took my-profile.php's Next out of the wizard: you could
               walk in and not out. */
            'privacy.php' => [
                'title' => 'Privacy Policy',
                'prev'  => ['terms.php', 'Terms'],
            ],
            /* Terms and Privacy are a PAIR of legal documents, and the only real
               sequence among the static pages — so they walk to each other. Do not
               chain anything else in here (see the note above about
               package -> contact -> privacy). */
            'terms.php' => [
                'title' => 'Terms of Use',
                'next'  => ['privacy.php', 'Privacy'],
            ],
            'about.php' => [
                'title' => 'About Us',
            ],
            'branches.php' => [
                'title' => 'Our Branches',
            ],
            /* Back to the plans page, because that is where you came from and where
               you go to change your mind. No prev/next: buying is not a sequence of
               sibling pages, and the real flow leaves the site for the gateway. */
            'checkout.php' => [
                'title' => 'Checkout',
                'back'  => ['package.php', 'Membership Plans'],
            ],
            /* Parent is Login, not Home: this page only makes sense as a detour out
               of the login form, and Back must lead where the member was trying to go. */
            'forgot-password.php' => [
                'title' => 'Reset Password',
                'back'  => ['login.php', 'Login'],
            ],
            'verification.php' => [
                'title' => 'Verify Profile',
                'back'  => ['my-profile.php', 'My Profile'],
            ],
            /* Back only. Success stories are not a step in anything — the couples
               are anchors on the one page, not sibling pages to walk between. */
            'success-stories.php' => [
                'title' => 'Success Stories',
            ],
        ];
    }

    function page_nav()
    {
        $map  = page_nav_map();
        $page = basename($_SERVER['PHP_SELF']);

        /* Home pages have no parent, and any page not in the map opts out silently
           rather than rendering a bar pointing at a guess. */
        if (!isset($map[$page])) {
            return;
        }

        $cfg  = $map[$page];
        $back = isset($cfg['back']) ? $cfg['back'] : [home_url(), is_logged_in() ? 'Dashboard' : 'Home'];
        $prev = isset($cfg['prev']) ? $cfg['prev'] : null;
        $next = isset($cfg['next']) ? $cfg['next'] : null;

        /* RUNTIME OVERRIDES. The map is static, but one page's siblings are not:
           single-profile.php walks MEMBERS, so its prev/next depend on ?id=. A page
           sets these before including header.php, in the same [url, label] shape:

               $page_nav_next = ['single-profile.php?id=12384', 'Meera Nair'];

           Anything set to null explicitly still means "no button", so a page can
           also drop a mapped one. Keep them out of page_nav_map(): the map is what
           the site's structure IS, and a value that changes per request isn't that. */
        foreach (['prev', 'next', 'title'] as $key) {
            $var = 'page_nav_' . $key;
            /* array_key_exists, not isset: `$page_nav_next = null;` is a page
               deliberately dropping a mapped button, and isset() cannot see it. */
            if (array_key_exists($var, $GLOBALS)) {
                $$key = $GLOBALS[$var];
            }
        }
        $title = isset($title) ? $title : $cfg['title'];
        ?>

        <nav class="page-nav" aria-label="Page">

            <div class="container is-chrome">

                <div class="page-nav-inner">

                    <!-- data-page-back: custom.js turns this into history.back() when the
                         referrer is a page of this site. Without JS (or on a deep link) the
                         href below is used, so it never dead-ends. -->
                    <a class="page-nav-back" href="<?php ee(url($back[0])); ?>" data-page-back>
                        <i class="fa fa-angle-left" aria-hidden="true"></i>
                        <span>Back<span class="page-nav-back-to"> to <?php ee($back[1]); ?></span></span>
                    </a>

                    <p class="page-nav-title"><?php ee($title); ?></p>

                    <div class="page-nav-steps">

                        <?php if ($prev): ?>
                            <a class="page-nav-step" href="<?php ee(url($prev[0])); ?>"
                               aria-label="Previous: <?php ee($prev[1]); ?>">
                                <i class="fa fa-angle-left" aria-hidden="true"></i>
                                <span class="page-nav-step-label"><?php ee($prev[1]); ?></span>
                            </a>
                        <?php endif; ?>

                        <?php if ($next): ?>
                            <a class="page-nav-step" href="<?php ee(url($next[0])); ?>"
                               aria-label="Next: <?php ee($next[1]); ?>">
                                <span class="page-nav-step-label"><?php ee($next[1]); ?></span>
                                <i class="fa fa-angle-right" aria-hidden="true"></i>
                            </a>
                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </nav>

        <?php
    }
}
