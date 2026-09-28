<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* Was profile.php, which claimed to be "My Profile" but rendered a match funnel —
   my-profile.php is the real own-profile page. Renamed to what it actually is: the
   member's PERSONAL match funnel (Latest / Yet to be Viewed / ...), as opposed to
   all-profiles.php, which is the full browsable directory. */

/* TODO(backend): demo data. Replace with the real match-funnel query.
   ONE source of truth: the counter bar and the sidebar nav both render from this,
   so their numbers cannot drift apart. They used to be typed out separately and
   disagreed wildly — the sidebar claimed 16 "Latest Matches" while the counter
   above it said 234, and "Mobile number" read 0 in the counter but listed 16 rows.
   Add a category here and both places pick it up. */
$funnel = [
    ['label' => 'Latest Matches',       'count' => 234, 'current' => true],
    ['label' => 'Yet to be Viewed',     'count' => 25,  'current' => false],
    ['label' => 'Viewed Not Connected', 'count' => 5,   'current' => false],
    ['label' => 'Mobile Numbers',       'count' => 0,   'current' => false],
];

/* TODO(backend): demo data. Replace with the real recommendations query.
   Was five byte-identical hand-copied cards, all "Anna thomas / VIS12370 / Newyork". */
$recommended = [
    ['name' => 'Anna Thomas',   'id' => 'VIS12370', 'pid' => 12370, 'img' => 'assets/images/matches/profile.webp',   'age' => '26 yrs', 'height' => "5'0\"", 'study' => 'MBA',    'work' => 'Consultant', 'place' => 'Thrissur',   'seen' => 'an hour ago', 'new' => true],
    ['name' => 'Meera Nair',    'id' => 'VIS12384', 'pid' => 12384, 'img' => 'assets/images/matches/600-600-1.webp', 'age' => '25 yrs', 'height' => "5'3\"", 'study' => 'B.Tech', 'work' => 'Engineer',   'place' => 'Kochi',      'seen' => 'today',       'new' => false],
    ['name' => 'Divya Krishna', 'id' => 'VIS12391', 'pid' => 12391, 'img' => 'assets/images/matches/profile.webp',   'age' => '28 yrs', 'height' => "5'2\"", 'study' => 'MBBS',   'work' => 'Doctor',     'place' => 'Kozhikode',  'seen' => '2 days ago',  'new' => true],
    ['name' => 'Sneha Menon',   'id' => 'VIS12402', 'pid' => 12402, 'img' => 'assets/images/matches/600-600-1.webp', 'age' => '27 yrs', 'height' => "5'4\"", 'study' => 'M.Com',  'work' => 'Banking',    'place' => 'Ernakulam',  'seen' => 'a week ago',  'new' => false],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'My Matches | Oppam Matrimony';
$page_desc     = 'Track your Kerala matrimony match funnel — latest matches, profiles yet to be viewed, and the interest you have received.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms, My Matches, Matrimony Match Funnel, Interest Received';
$og_desc       = 'Your personal Kerala matrimony match funnel: latest matches, profiles yet to be viewed and interest received.';
$page_robots   = 'noindex, nofollow';   // behind auth / names a member

?>
<!doctype html>
<html lang="en">

<head>
<?php include __DIR__ . '/assets/includes/head.php'; ?>
</head>

<body>

  <?php include_once('assets/includes/header.php') ?>

    <main id="main" tabindex="-1">

  <!-- The design shows no page title here; this gives the document a
       proper top-level heading without changing anything visually. -->
  <h1 class="visually-hidden">My Matches</h1>

  <!-- =========================
      MY MATCHES — the canonical 3 / 6 / 3 split from dashboard.php:
      match funnel (col-3) | funnel counters + results (col-6) | ads (col-3).
      This page was the last 3/9 outlier on the site.
  ========================= -->

  <section class="dashboard-section myhome-section" data-mobile-rail data-rail-label="My Matches">
    <div class="container">
      <div class="row dashboard-row">

        <!-- =========================
            LEFT RAIL — match categories + the interest this member has received.
        ========================= -->

        <div class="col-lg-3 col-md-12 col-sm-12 col-12">

          <!-- Was a Bootstrap accordion of four groups, each holding an identical
               Inbox/Sent tab pair with an identical status list (Pending/Accepted/
               Decline/Need time/Declined) — the same block copy-pasted eight times,
               with "Decline" and "Declined" both present as separate states. It also
               reused the same DOM ids (#home, #profile, #myTab) across all four
               panels, so the tabs switched the wrong panel's content. It is now the
               shared sidebar nav used by interest.php and search.php. -->
          <!-- The rail is STICKY AS A UNIT, not card-by-card. .side-bar carries its
               own sticky rule (see style.css), but this page stacks two more cards
               under it — pinning only the nav made it a positioned element that
               scrolled over the Interest Received card and painted on top of it.
               .rail-stack takes the sticky instead; the nav's own rule is cancelled
               inside it. Keep the cards inside this wrapper. -->
          <div class="rail-stack">

          <nav class="side-bar" aria-label="Match categories" data-rail="menu">

            <p class="sidebar-section-title">My Matches</p>

            <?php foreach ($funnel as $f): ?>

              <!-- TODO(backend): these should carry a query string, e.g. ?box=latest -->
              <a href="#"
                 class="nav-link-custom<?php ee($f['current'] ? ' active' : ''); ?>"
                 <?php ee($f['current'] ? 'aria-current="true"' : ''); ?>>
                <?php ee($f['label']); ?>
                <span class="nav-count"><?php ee($f['count']); ?></span>
              </a>

            <?php endforeach; ?>

            <div class="sidebar-divider"></div>

            <p class="sidebar-section-title">Browse</p>

            <a href="all-profiles" class="nav-link-custom">
              <span class="nav-link-label">
                <i class="fa fa-users" aria-hidden="true"></i>
                All Profiles
              </span>
              <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
            </a>

            <a href="daily-matches" class="nav-link-custom">
              <span class="nav-link-label">
                <i class="fa fa-bolt" aria-hidden="true"></i>
                Daily Matches
              </span>
              <span class="nav-count"><i class="fa fa-angle-right" aria-hidden="true"></i></span>
            </a>

          </nav>

          <div class="interest-parent" data-rail="weave">
            <!-- This card is built from .success-one's classes on purpose — it sits
                 directly above that card in the same rail, so it uses the same
                 header, the same image/detail row and the same name / .pf-id /
                 .dt-list stack. It had its own parallel set (.rail-card-header,
                 .interested-section, .interest-content) that agreed with nothing.
                 Only the actions below are its own.
                 The header used to be a filled teal band. It now follows the red
                 .profile-card language: white surface, red rule + kicker, red pill
                 actions. See .success-one-head in style.css. -->
            <div class="success-one-head interest-head">
              <h2>Interest Received</h2>
              <span class="interest-head-meta">1 pending</span>
            </div>
            <div class="success-detail">
              <div class="success-image">
                <img src="assets/images/details/001.jpg" class="img-fluid" alt="" width="600" height="600" loading="lazy" decoding="async">
              </div>
              <div class="success-one-pgh">
                <h2>Soumya</h2>
                <p class="pf-id">V2315778</p>
                <ul class="dt-list">
                  <li>26 yrs, 5 ft</li>
                </ul>
              </div>
            </div>
            <div class="my-intrest-btn">
              <p>She has sent you an interest. Would you like to accept?</p>
              <!-- TODO(backend): these were <button type="submit"> with NO enclosing <form>,
                   so they submitted nothing — inert. Anchors until a handler exists; restore
                   them to <button type="submit"> inside a <form> when it does. -->
              <a href="#" class="btn yes-btn"><i class="fa fa-heart" aria-hidden="true"></i>Yes</a>
              <a href="#" class="btn no-btn"><i class="fa fa-window-close" aria-hidden="true"></i>No</a>
            </div>
            <div class="interest-btn">
              <a href="interest">Yes All<i class="fa fa-arrow-right"></i></a>
            </div>
          </div>

          <!-- The "She is Interested" (.success-one) widget used to sit here, under
               Interest Received. Removed — it said the same thing as the card above
               it. The widget still exists on search.php. -->

          </div><!-- /.rail-stack -->

        </div>


        <!-- =========================
            CENTRE — funnel counters + recommended results
        ========================= -->

        <div class="col-lg-6 col-md-12 col-sm-12 col-12">

          <!-- Same $funnel array as the sidebar nav — the two cannot disagree. -->
          <div class="counter-section">
            <!-- Card header for the mobile treatment (below 992px this panel is a
                 white card like every other one, so it needs a title the way
                 .content-header gives one). Hidden on desktop, where the panel is
                 the brand-red accent band and a header would fight the numbers. -->
            <div class="counter-header">
              <h2>My Matches</h2>
              <span class="counter-header-meta">Your match funnel</span>
            </div>
            <!-- 2 x 2 on desktop, not 4 across: this panel now lives in the col-6
                 centre column, and four counters side by side crushed the labels. -->
            <div class="row">
              <?php foreach ($funnel as $f): ?>
                <div class="col-lg-6 col-md-3 col-sm-3 col-12">
                  <div class="counter-sec">
                    <div class="counter-no">
                      <span class="counter-value"><?php ee($f['count']); ?></span>
                      <span class="counter-label"><?php ee($f['label']); ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Each card used to be wrapped in <a class="profile-link"> while ALSO
               containing anchors ("Don't show", "Send Interest", "View profile").
               An <a> may not contain an <a>, so the parser hoisted the cards out of
               this column and re-parented them over the footer. Same fix as
               all-profiles.php: a plain <div> plus .stretched-link on the name. -->
          <!-- .profile-grid is `display: contents` on desktop — the rows stay a flat
               list exactly as before. Below 768px it becomes a 2-up grid. -->
          <div class="profile-grid">

          <?php foreach ($recommended as $m): ?>
              <?php
              $profile = $m;
              include __DIR__ . '/assets/includes/profile-row.php';
              ?>
          <?php endforeach; ?>

          </div><!-- /.profile-grid -->

          <?php $pg_label = 'Recommended profile pages'; ?>
          <?php include('assets/includes/pagination.php'); ?>

        </div>


        <!-- =========================
            ADS RAIL — shared with dashboard.php, plus this page's own promos.
        ========================= -->

        <div class="col-lg-3 col-md-12 col-sm-12 col-12">

          <?php include_once('assets/includes/ads.php') ?>

          <div class="advertisement-section" data-rail="weave">
            <div class="advertisement-text">
              <p>Members trust &amp; respond to profiles with verified information</p>
            </div>
            <div class="advertisement-btn">
              <!-- This was href="" (which RELOADED the page), then inert. verification.php
                   is the flow it always meant. -->
              <a href="verification">Add Identity Badge</a>
            </div>
          </div>

          <!-- This card was unlinked for a long time: its only candidate target,
               details.php, was a member profile detail view ("Krishna Priya TS"), not
               a success-stories page. success-stories.php now exists, so the card
               links there. The link is on the heading, NOT a wrapper around the card:
               an <a> may not contain an <a>, and .stretched-link would be no use here
               because .success-content h2 is itself absolutely positioned, so the
               stretched ::after would resolve against the banner, not the card. -->
          <div class="success-stories" data-rail="weave">
            <div class="success-img">
              <img src="assets/images/details/s1.jpg" class="img-fluid" alt="" width="400" height="400" loading="lazy" decoding="async">
              <div class="success-content">
                <h2 class="script-accent">
                  <a href="success-stories">Success Stories</a>
                </h2>
                <p>Thanks to oppam matrimony we found each other and now got married.all thanks to the team</p>
              </div>
            </div>
          </div>

        </div>

      </div>
    </div>
  </section>

    </main>

  <?php include_once('assets/includes/footer.php') ?>
  <?php include_once('assets/includes/footer2.php') ?>
  <?php include_once('assets/includes/script.php') ?>
</body>

</html>
