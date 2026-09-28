<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* The directory rows live in assets/includes/profiles-data.php, because
   single-profile.php's Prev / Next PROFILE arrows walk the SAME array — "next
   profile" only means anything if the listed order and the walked order are one
   list. TODO(backend): swap profile_directory() for the real match query. */
require_once __DIR__ . '/assets/includes/profiles-data.php';
$matches = profile_directory();

/* The left rail. Nothing pointed anywhere before — every link was href="".
   "Viewed You" / "Shortlisted You" both used to point at profile.php, which was
   neither: they are boxes of the personal funnel, so they go to my-matches.php. */
$match_nav = [
    'Browse' => [
        ['icon' => 'fa-users', 'title' => 'All Profiles',    'desc' => 'Every profile that matches your preferences', 'href' => 'all-profiles.php',  'current' => true],
        ['icon' => 'fa-bolt',  'title' => 'Daily Matches',   'desc' => "Today's hand-picked recommendations",         'href' => 'daily-matches.php', 'current' => false],
    ],
    'Based on Activity' => [
        ['icon' => 'fa-heart', 'title' => 'My Matches',      'desc' => 'Your personal match funnel',            'href' => 'my-matches.php', 'current' => false],
        ['icon' => 'fa-star',  'title' => 'Shortlisted',     'desc' => 'Matches you have shortlisted',          'href' => 'interest.php',   'current' => false],
        ['icon' => 'fa-eye',   'title' => 'Viewed You',      'desc' => 'Matches who have viewed your profile',  'href' => 'my-matches.php', 'current' => false],
        ['icon' => 'fa-user',  'title' => 'Shortlisted You', 'desc' => 'Matches who have shortlisted you',      'href' => 'my-matches.php', 'current' => false],
    ],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'All Profiles | Oppam Matrimony';
$page_desc     = 'Discover compatible Kerala matrimony matches based on interests, profession, education, lifestyle, and partner preferences.';
$page_keywords = 'Kerala Matrimony Matches, Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Discover Your Perfect Kerala Matrimony Match Today';
$og_desc       = 'Explore personalized Kerala matrimony matches selected based on compatibility, interests, and relationship preferences.';
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
        ALL PROFILES — the full browsable directory (was matches.php; renamed so it
        is not confused with my-matches.php, the member's personal funnel).
        Same 3 / 6 / 3 split as dashboard.php:
        browse nav (col-3) | results (col-6) | ads (col-3)
    ========================= -->

    <section class="dashboard-section matches-section" data-mobile-rail data-rail-label="Browse">

        <div class="container">

            <div class="row dashboard-row">

                <!-- =========================
                    LEFT RAIL — match navigation.
                    White side-bar card, same design as interest.php.
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <nav class="side-bar" aria-label="Match categories" data-rail="menu">

                        <?php foreach ($match_nav as $group => $items): ?>

                            <p class="sidebar-section-title"><?php ee($group); ?></p>

                            <?php foreach ($items as $item): ?>

                                <a href="<?php ee(url($item['href'])); ?>"
                                   class="nav-link-custom<?php ee($item['current'] ? ' active' : ''); ?>"
                                   <?php ee($item['current'] ? 'aria-current="true"' : ''); ?>>

                                    <span class="nav-link-label">
                                        <i class="fa <?php ee($item['icon']); ?>" aria-hidden="true"></i>
                                        <?php ee($item['title']); ?>
                                    </span>

                                    <span class="nav-count">
                                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                                    </span>

                                </a>

                            <?php endforeach; ?>

                            <div class="sidebar-divider"></div>

                        <?php endforeach; ?>

                    </nav>

                </div>


                <!-- =========================
                    CENTRE — RESULTS
                ========================= -->

                <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-content match-results">

                        <section class="home-content">

                            <div class="content-header">

                                <div class="content-title">
                                    <h1>All Profiles</h1>
                                    <p>838 profiles based on your partner preferences</p>
                                </div>

                                <!-- TODO(backend): no handler — sorting is inert. -->
                                <div class="sort-by">
                                    <label class="form-label" for="sort">Sort by</label>
                                    <select class="form-select profile-select" id="sort" name="sort">
                                        <option>Relevance</option>
                                        <option>Recently Active</option>
                                        <option>Newest First</option>
                                        <option>Age: Low to High</option>
                                    </select>
                                </div>

                            </div>

                            <!-- .profile-grid is `display: contents` on desktop — the rows stay a flat
                                 list exactly as before. Below 768px it becomes a 2-up grid. -->
                            <div class="profile-grid">

                            <?php foreach ($matches as $m): ?>
                                <?php
                                $profile = $m;
                                include __DIR__ . '/assets/includes/profile-row.php';
                                ?>
                            <?php endforeach; ?>

                            </div><!-- /.profile-grid -->

                        </section>

                    </div>

                    <!-- The pager sits OUTSIDE the content card, directly in the column —
                         same as daily-matches.php / my-matches.php. It paginates the card,
                         it is not part of it. -->
                    <?php $pg_label = 'Profile listing pages'; ?>
                    <?php include('assets/includes/pagination.php'); ?>

                </div>


                <!-- =========================
                    ADS RAIL — shared with dashboard.php
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
