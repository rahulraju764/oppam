<?php
require_once __DIR__ . '/auth.php';
/* page_nav_map() — header.php has already loaded this, but footer2.php must not
   depend on include order to know the wizard's step sequence. */
require_once __DIR__ . '/pagenav.php';
?>

<!-- MOBILE FOOTER — member only. A logged-out visitor gets Login / Register
     from the header instead, so this bar is not rendered on public pages.

     Each tab lights for its whole section, using the same nav_active() as the
     desktop header (defined in auth.php) — one active-state mechanism, not two.

     FIVE tabs, two of which open a bottom sheet:
     Home | Matches | Interests | Search | Profile.
     Matches and Interests are the two sections that fan out into several pages, and
     both now fan out the SAME way. Previously Matches was a single tab hardwired to
     one of its three pages while the desktop header offered all three in a dropdown,
     so the same word led to different places depending on the screen. The sheet is
     the mobile equivalent of that dropdown.

     The machinery is generic — custom.js resolves a toggle's sheet through its
     aria-controls, and every rule in style.css is class-based — so a second sheet is
     markup only. Wire a new one by matching aria-controls to the sheet's id. -->

<?php if (is_logged_in()): ?>

<?php
/* WIZARD PREV / NEXT, inside the tab bar.
   The page-nav strip at the top of the page already carries these, but it scrolls
   away — and on the six wizard steps that is exactly when they are wanted, at the
   BOTTOM of a long form. So the same prev/next pair is repeated here, in the one
   piece of chrome that is always on screen below 992px.

   It reads page_nav_map() rather than a second list, so a step order changed in
   pagenav.php moves this too. It renders ONLY inside the profile wizard: the
   Matches trio are siblings you browse, not a flow you are walking through, and a
   fixed "Next" under them would read as a pager for the results. */
$wizard_pages = ['profile-creation.php', 'education.php', 'family.php', 'partner.php', 'contact-details.php', 'profile-photos.php'];
$wiz_page     = basename($_SERVER['PHP_SELF']);
$wiz_map      = page_nav_map();
$wiz          = in_array($wiz_page, $wizard_pages, true) && isset($wiz_map[$wiz_page]) ? $wiz_map[$wiz_page] : null;
?>

<nav class="mobile-footer d-lg-none" aria-label="Primary">

    <?php if ($wiz): ?>
        <!-- A full-width row INSIDE the bar rather than a second fixed element:
             it then inherits the bar's --bar-lift, and measureBar() in custom.js
             folds its height into --tab-bar-h automatically, so the Interests
             sheet still rests on top of the bar and nothing sits under it. -->
        <div class="wizard-steps">

            <?php if (isset($wiz['prev'])): ?>
                <a class="wizard-step" href="<?php ee(url($wiz['prev'][0])); ?>">
                    <i class="fa fa-angle-left" aria-hidden="true"></i>
                    <span>Prev<span class="wizard-step-to">: <?php ee($wiz['prev'][1]); ?></span></span>
                </a>
            <?php endif; ?>

            <p class="wizard-steps-title"><?php ee($wiz['title']); ?></p>

            <?php if (isset($wiz['next'])): ?>
                <a class="wizard-step is-next" href="<?php ee(url($wiz['next'][0])); ?>">
                    <span>Next<span class="wizard-step-to">: <?php ee($wiz['next'][1]); ?></span></span>
                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                </a>
            <?php endif; ?>

        </div>
    <?php endif; ?>

    <a href="dashboard"
       class="<?php ee(nav_active('dashboard.php') ? 'is-current' : ''); ?>"
       <?php ee(nav_active('dashboard.php') ? 'aria-current="page"' : ''); ?>>
        <i class="fa fa-home" aria-hidden="true"></i>
        <span>Home</span>
    </a>

    <!-- MATCHES opens a sheet rather than navigating, because the section has three
         destinations and no one of them is the obvious default — the same reason the
         desktop header makes it a dropdown. It lights for the whole section, including
         single-profile.php (you reached that member's profile from one of the three). -->
    <?php $in_matches = nav_active('all-profiles.php', 'my-matches.php', 'daily-matches.php', 'single-profile.php'); ?>

    <button type="button"
            class="tab-sheet-toggle<?php ee($in_matches ? ' is-current' : ''); ?>"
            aria-expanded="false"
            aria-controls="matches-sheet">
        <i class="fa fa-heart" aria-hidden="true"></i>
        <span>Matches</span>
    </button>

    <!-- INTERESTS lifts the second sheet, which carries the interest filters (the
         rail that used to live inside interest.php as a floating filter button)
         plus jump-off links. One tap gets to any box; the old flow was
         tab -> page -> FAB -> drawer. -->
    <button type="button"
            class="tab-sheet-toggle<?php ee(nav_active('interest.php') ? ' is-current' : ''); ?>"
            aria-expanded="false"
            aria-controls="interests-sheet">
        <i class="fa fa-address-book" aria-hidden="true"></i>
        <span>Interests</span>
    </button>

    <!-- The class here used to be .mobile-notification — a copy-paste artifact from
         the bell icon. This is the Search tab. -->
    <a href="search"
       class="<?php ee(nav_active('search.php') ? 'is-current' : ''); ?>"
       <?php ee(nav_active('search.php') ? 'aria-current="page"' : ''); ?>>
        <i class="fa fa-search" aria-hidden="true"></i>
        <span>Search</span>
    </a>

    <!-- PROFILE, rightmost — the conventional "me" slot in a mobile tab bar.

         This slot has churned: it was "Messages" -> faq.php (wrong, no messaging page
         exists), then "Profile", then "All Profiles". All Profiles moved into the
         Matches sheet, which freed it again.

         It IS also reachable from the avatar in .mobile-header, and that is why it was
         dropped last time — but the header scrolls away with the page while this bar is
         fixed, so the two are not equivalent. Because it is a tab now, my-profile.php
         must NOT be repeated in either sheet's "Go to" grid.

         messages.php now exists, and the slot was NOT given back to it. Three churns
         is enough: Profile is the conventional rightmost tab, and Messages would push
         Profile back into a sheet — which is precisely the arrangement that was
         reversed last time. Messages is in the drawer's account block instead (see
         header.php), next to My Profile and Edit Profile.
         Revisit only if messaging becomes a primary section rather than an inbox. -->
    <a href="my-profile"
       class="<?php ee(nav_active('my-profile.php', 'profile-creation.php', 'education.php', 'family.php', 'partner.php', 'contact-details.php', 'profile-photos.php') ? 'is-current' : ''); ?>"
       <?php ee(nav_active('my-profile.php') ? 'aria-current="page"' : ''); ?>>
        <i class="fa fa-user-o" aria-hidden="true"></i>
        <span>Profile</span>
    </a>

</nav>

<?php
/* The three Matches destinations, in the same order and with the same icons as the
   Matches dropdown in header.php — one section, one ordering, whichever screen you
   are on. Keep the two lists in step when a destination is added.

   These are NOT interchangeable pages (see CLAUDE.md): all-profiles is the full
   directory, my-matches is this member's own funnel, daily-matches is today's picks.
   The one-line descriptions are here because the labels alone do not say that. */
$matches_links = [
    ['label' => 'All Profiles',  'icon' => 'fa-users', 'href' => 'all-profiles.php',  'desc' => 'Browse everyone'],
    ['label' => 'My Matches',    'icon' => 'fa-heart', 'href' => 'my-matches.php',    'desc' => 'Your funnel'],
    ['label' => 'Daily Matches', 'icon' => 'fa-bolt',  'href' => 'daily-matches.php', 'desc' => "Today's picks"],
];
?>

<div class="tab-sheet d-lg-none" id="matches-sheet" hidden
     role="dialog" aria-modal="true" aria-label="Matches">

    <div class="tab-sheet-panel">

        <div class="tab-sheet-grip" aria-hidden="true"></div>

        <div class="tab-sheet-head">
            <p class="tab-sheet-title">Matches</p>
            <button type="button" class="tab-sheet-close" aria-label="Close Matches">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div class="tab-sheet-body">

            <p class="tab-sheet-group">Browse</p>

            <div class="tab-sheet-links">
                <?php foreach ($matches_links as $l): ?>
                    <?php $is_here = nav_active($l['href']); ?>
                    <a href="<?php ee(url($l['href'])); ?>"
                       class="tab-sheet-link<?php ee($is_here ? ' active' : ''); ?>"
                       <?php ee($is_here ? 'aria-current="page"' : ''); ?>>
                        <i class="fa <?php ee($l['icon']); ?>" aria-hidden="true"></i>
                        <span><?php ee($l['label']); ?></span>
                        <small class="tab-sheet-link-desc"><?php ee($l['desc']); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Home, Search and Profile are tabs one thumb away, so they are not
                 repeated here. That leaves the OTHER section plus the upgrade CTA —
                 the mirror image of the Interests sheet's own "Go to". -->
            <p class="tab-sheet-group">Go to</p>

            <div class="tab-sheet-links">
                <a href="interest" class="tab-sheet-link<?php ee(nav_active('interest.php') ? ' active' : ''); ?>">
                    <i class="fa fa-address-book" aria-hidden="true"></i>
                    <span>Interests</span>
                </a>
                <a href="package" class="tab-sheet-link<?php ee(nav_active('package.php') ? ' active' : ''); ?>">
                    <i class="fa fa-diamond" aria-hidden="true"></i>
                    <span>Upgrade</span>
                </a>
            </div>

        </div>

    </div>

</div>

<?php
/* TODO(backend): hardcoded demo counts, mirroring the $filters array that used to
   live in interest.php. Replace with the real interest counts for the session user.
   The hrefs are the query strings the listing page will read. */
$sheet_boxes = [
    'Interests Received' => [
        ['label' => 'All',                'count' => 17, 'q' => 'received'],
        ['label' => 'Pending',            'count' => 2,  'q' => 'received&status=pending'],
        ['label' => 'Accepted / replied', 'count' => 1,  'q' => 'received&status=accepted'],
        ['label' => 'Declined',           'count' => 14, 'q' => 'received&status=declined'],
    ],
    'Interests Sent' => [
        ['label' => 'All',                'count' => 9,  'q' => 'sent'],
        ['label' => 'Pending',            'count' => 6,  'q' => 'sent&status=pending'],
        ['label' => 'Accepted / replied', 'count' => 2,  'q' => 'sent&status=accepted'],
        ['label' => 'Declined',           'count' => 1,  'q' => 'sent&status=declined'],
    ],
];

/* Jump-off links, so the sheet is a navigation surface and not just a filter list.
   Only destinations the bar above does not already carry — Home, Search and Profile
   each have their own tab one thumb away, so repeating them here was dead weight.
   That leaves the other section plus the upgrade CTA, mirroring the Matches sheet.

   My Matches used to sit here; the three matches pages are now listed in ONE place,
   the Matches sheet. Don't add it back — a section page that appears in both sheets
   makes neither one authoritative. */
$sheet_links = [
    ['label' => 'Matches', 'icon' => 'fa-heart',   'href' => 'all-profiles.php'],
    ['label' => 'Upgrade', 'icon' => 'fa-diamond', 'href' => 'package.php'],
];

/* Which filter is current — only meaningful while we are ON interest.php.
   TODO(backend): derive from $_GET['box'] / $_GET['status'] once the page filters. */
$sheet_current = nav_active('interest.php') ? 'received&status=pending' : null;
?>

<!-- The sheet is a sibling of the bar, both fixed, so it is not clipped by the
     bar's own box. hidden until opened — see the TAB SHEET block in custom.js. -->
<div class="tab-sheet d-lg-none" id="interests-sheet" hidden
     role="dialog" aria-modal="true" aria-label="Interests">

    <div class="tab-sheet-panel">

        <div class="tab-sheet-grip" aria-hidden="true"></div>

        <div class="tab-sheet-head">
            <p class="tab-sheet-title">Interests</p>
            <button type="button" class="tab-sheet-close" aria-label="Close Interests">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div class="tab-sheet-body">

            <?php foreach ($sheet_boxes as $group => $items): ?>

                <!-- .is-received / .is-sent colour the two box groups apart —
                     brand red for what came in, teal for what went out. "Go to"
                     below stays muted grey: it is a different kind of thing. -->
                <p class="tab-sheet-group <?php ee($group === 'Interests Sent' ? 'is-sent' : 'is-received'); ?>">
                    <?php ee($group); ?>
                </p>

                <div class="tab-sheet-list">
                    <?php foreach ($items as $f): ?>
                        <?php $is_here = ($sheet_current === $f['q']); ?>
                        <a href="interest?box=<?php ee($f['q']); ?>"
                           class="tab-sheet-item<?php ee($is_here ? ' active' : ''); ?>"
                           <?php ee($is_here ? 'aria-current="true"' : ''); ?>>
                            <?php ee($f['label']); ?>
                            <span class="nav-count"><?php ee($f['count']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

            <?php endforeach; ?>

            <p class="tab-sheet-group">Go to</p>

            <div class="tab-sheet-links">
                <?php foreach ($sheet_links as $l): ?>
                    <a href="<?php ee(url($l['href'])); ?>" class="tab-sheet-link">
                        <i class="fa <?php ee($l['icon']); ?>" aria-hidden="true"></i>
                        <span><?php ee($l['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

        </div>

    </div>

</div>

<?php endif; ?>
