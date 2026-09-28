<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* TODO(backend): hardcoded demo data — both rows used to be the SAME member
   ("Anna thomas", VIS12370) pasted twice. Replace with the real interests query. */
$interests = [
    ['name' => 'Anna Thomas',   'id' => 'VIS12370', 'pid' => 12370, 'img' => 'assets/images/matches/profile.webp',   'age' => '26 yrs', 'height' => "5'0\"", 'study' => 'MBA',   'work' => 'Consultant', 'place' => 'Thrissur',  'when' => '2 hours ago', 'status' => 'pending',  'new' => true],
    ['name' => 'Meera Nair',    'id' => 'VIS12384', 'pid' => 12384, 'img' => 'assets/images/matches/600-600-1.webp', 'age' => '25 yrs', 'height' => "5'3\"", 'study' => 'B.Tech', 'work' => 'Engineer',   'place' => 'Kochi',     'when' => 'yesterday',   'status' => 'pending',  'new' => false],
    ['name' => 'Divya Krishna', 'id' => 'VIS12391', 'pid' => 12391, 'img' => 'assets/images/matches/profile.webp',   'age' => '28 yrs', 'height' => "5'2\"", 'study' => 'MBBS',   'work' => 'Doctor',     'place' => 'Kozhikode', 'when' => '3 days ago',  'status' => 'accepted', 'new' => false],
    ['name' => 'Sneha Menon',   'id' => 'VIS12402', 'pid' => 12402, 'img' => 'assets/images/matches/600-600-1.webp', 'age' => '27 yrs', 'height' => "5'4\"", 'study' => 'M.Com',  'work' => 'Banking',    'place' => 'Ernakulam', 'when' => 'last week',   'status' => 'declined', 'new' => false],
];

$status_label = ['pending' => 'Awaiting your reply', 'accepted' => 'Accepted', 'declined' => 'Declined'];

/* The filter rail. Every link was href="#" before — none of them filtered. */
$filters = [
    'Interests Received' => [
        // "All" is the sum of the three states below it — it read 15 against a
        // breakdown of 2 + 1 + 14 = 17.
        ['label' => 'All',                'count' => 17, 'current' => false],
        ['label' => 'Pending',            'count' => 2,  'current' => true],
        ['label' => 'Accepted / replied', 'count' => 1,  'current' => false],
        ['label' => 'Declined',           'count' => 14, 'current' => false],
    ],
    'Interests Sent' => [
        ['label' => 'All',                'count' => 9,  'current' => false],
        ['label' => 'Pending',            'count' => 6,  'current' => false],
        ['label' => 'Accepted / replied', 'count' => 2,  'current' => false],
        ['label' => 'Declined',           'count' => 1,  'current' => false],
    ],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Matrimony Interests & Requests | Oppam Matrimony';
$page_desc     = 'Manage sent and received matrimony interests, respond to profile requests, and build meaningful Kerala matrimony connections.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Connect Through Trusted Matrimony Interests Online';
$og_desc       = 'Track sent and received interests and build meaningful Kerala matrimony connections with Oppam Matrimony.';
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

    <!-- =========================
        INTERESTS — same 3 / 6 / 3 split as dashboard.php:
        filters (col-3) | interest list (col-6) | ads (col-3)
    ========================= -->

    <section class="dashboard-section interest-section" data-mobile-rail data-rail-label="Filters">

        <div class="container">

            <div class="row dashboard-row">

                <!-- =========================
                    LEFT RAIL — filters
                ========================= -->

                <!-- Desktop only. On mobile these filters live in the Interests
                     bottom sheet (footer2.php), lifted by the Interests tab —
                     there is no in-page filter button here any more. The section
                     keeps data-mobile-rail so the ad widgets still weave into the
                     column; it just carries no data-rail="menu". -->
                <div class="col-lg-3 d-none d-lg-block">

                    <nav class="side-bar" aria-label="Interest filters">

                        <?php foreach ($filters as $group => $items): ?>

                            <p class="sidebar-section-title"><?php ee($group); ?></p>

                            <?php foreach ($items as $f): ?>

                                <!-- TODO(backend): these should carry a query string, e.g. ?box=received&status=pending -->
                                <a href="#"
                                   class="nav-link-custom<?php ee($f['current'] ? ' active' : ''); ?>"
                                   <?php ee($f['current'] ? 'aria-current="true"' : ''); ?>>
                                    <?php ee($f['label']); ?>
                                    <span class="nav-count"><?php ee($f['count']); ?></span>
                                </a>

                            <?php endforeach; ?>

                            <div class="sidebar-divider"></div>

                        <?php endforeach; ?>

                    </nav>

                </div>


                <!-- =========================
                    CENTRE — the interest list
                ========================= -->

                <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-content match-results">

                        <section class="home-content">

                            <div class="content-header">

                                <div class="content-title">
                                    <h1>Interests Received</h1>
                                    <p>2 pending — reply before they expire</p>
                                </div>

                            </div>

                            <!-- Rows in the RECEIVED box get Accept / Decline. They previously
                                 offered to send an interest back to someone who had already sent
                                 one — the wrong action for this list. A settled row shows its
                                 status chip instead of buttons. -->

                            <!-- .profile-grid is `display: contents` on desktop — the rows stay a flat
                                 list exactly as before. Below 768px it becomes a 2-up grid so a phone
                                 shows two profiles per screen row. See responsive.css. -->
                            <div class="profile-grid">

                            <?php foreach ($interests as $i): ?>
                                <?php
                                $profile = $i;
                                $profile['status_label'] = $status_label[$i['status']];
                                $profile_actions = 'respond';
                                include __DIR__ . '/assets/includes/profile-row.php';
                                ?>
                            <?php endforeach; ?>

                            </div><!-- /.profile-grid -->

                        </section>

                    </div>

                    <!-- Outside the content card, in the column — as on every other listing page. -->
                    <?php $pg_label = 'Interest list pages'; ?>
                    <?php include('assets/includes/pagination.php'); ?>

                </div>


                <!-- =========================
                    ADS RAIL — shared with dashboard.php / all-profiles.php
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <?php include_once('assets/includes/ads.php') ?>

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
