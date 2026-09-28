<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* TODO(backend): demo data for the two sliders. Replace with the real
   daily-recommendations and all-matches queries.

   These were TEN hand-written .profile-card blocks — the same five members,
   the same five photos, pasted verbatim into BOTH rails, so "Daily
   Recommendations" and "All Matches" showed an identical row of people. They
   are now two lists rendered through assets/includes/profile-tile.php. */
$daily_picks = [
    ['name' => 'Sethulakshmi', 'age' => '26 Yrs', 'height' => "5'4\"", 'img' => 'assets/images/home/profile1.webp'],
    ['name' => 'Reshmi',       'age' => '28 Yrs', 'height' => "5'2\"", 'img' => 'assets/images/home/profile2.webp'],
    ['name' => 'Keerthi',      'age' => '25 Yrs', 'height' => "5'3\"", 'img' => 'assets/images/home/profile3.webp'],
    ['name' => 'Priyanka',     'age' => '25 Yrs', 'height' => "5'3\"", 'img' => 'assets/images/home/profile4.webp'],
    ['name' => 'Arya S',       'age' => '25 Yrs', 'height' => "5'3\"", 'img' => 'assets/images/home/profile5.webp'],
];

$all_matches = [
    ['name' => 'Aparna',   'age' => '27 Yrs', 'height' => "5'1\"", 'img' => 'assets/images/home/profile6.webp'],
    ['name' => 'Lakshmi',  'age' => '24 Yrs', 'height' => "5'5\"", 'img' => 'assets/images/home/profile7.webp'],
    ['name' => 'Nandana',  'age' => '29 Yrs', 'height' => "5'2\"", 'img' => 'assets/images/home/profile8.webp'],
    ['name' => 'Devika',   'age' => '26 Yrs', 'height' => "5'3\"", 'img' => 'assets/images/home/webp-women-01.webp'],
    ['name' => 'Sreya',    'age' => '25 Yrs', 'height' => "5'4\"", 'img' => 'assets/images/home/webp-women-02.webp'],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Oppam Matrimony | Kerala\'s Trusted Matchmaking Platform';
$page_desc     = 'Explore Kerala\'s leading matrimonial platform designed to help brides and grooms find compatible life partners.';
$page_keywords = 'Kerala Matrimony Platform, Matrimony Kerala, Oppam Matrimony, Marriage Matchmaking Kerala';
$og_title      = 'Welcome to Oppam Matrimony';
$og_desc       = 'Start your matrimonial journey with trusted matchmaking services across Kerala.';
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
    <h1 class="visually-hidden">Dashboard</h1>

    <!-- =========================
        DASHBOARD SECTION
========================= -->

    <section class="dashboard-section">

        <div class="container">

            <div class="row dashboard-row">

                <!-- =========================
                SIDEBAR
            ========================= -->

                <!-- col-md-12, not col-md-3: its two siblings are col-md-12, so a 3-wide
                     sidebar left the content to wrap underneath with dead space beside it.
                     matches/interest/search all stack all three columns at md. -->
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-sidebar">

                        <div class="sidebar-profile">

                            <a href="profile-photos">
                                <img src="assets/images/home/profile1.webp" alt="" width="600" height="600" loading="lazy" decoding="async">
                            </a>

                            <h3>Krishna Priya</h3>

                            <p>VishwakarmaMatrimony</p>

                            <h4>VIS446178</h4>

                            <span>Free Member</span>

                        </div>

                        <div class="membership-box">

                            <p>
                                Upgrade membership to call/chat with matches
                            </p>

                            <a href="package" class="upgrade-btn">
                                Upgrade Now
                            </a>

                        </div>

                        <div class="sidebar-menu">

                            <!-- Mirrors the header dropdown: one entry, pointing at the profile
                                 rather than at wizard steps 1 and 4. -->
                            <a href="my-profile">
                                <i class="fa fa-user-o"></i>
                                My Profile
                            </a>

                            <a href="my-profile#partner-preferences">
                                <i class="fa fa-sliders"></i>
                                Partner Preferences
                            </a>

                            <a href="my-matches">
                                <i class="fa fa-heart-o"></i>
                                My Matches
                            </a>

                            <a href="all-profiles">
                                <i class="fa fa-users"></i>
                                All Profiles
                            </a>

                            <a href="messages">
                                <i class="fa fa-envelope-o"></i>
                                Messages
                            </a>

                            <!-- This once pointed at profile.php, a match-list page — nothing to
                                 do with verification — and was then made inert. verification.php
                                 is the real flow. -->
                            <a href="verification">
                                <i class="fa fa-check-circle-o"></i>
                                Verify Profile
                            </a>

                        </div>

                    </div>

                </div>

                <!-- =========================
                CONTENT AREA — centre column of the 3/6/3 split
            ========================= -->

                <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                    <!-- FIRST CONTENT -->

                    <div class="dashboard-content mb-4">

                        <section class="home-content">

                            <div class="content-header">

                                <div class="content-title">

                                    <h2>Daily Recommendations(8)</h2>

                                    <p>Recommended matches for today</p>

                                </div>

                                <div class="time-card">

                                    <span>Time left to view</span>

                                    <h4>10h:32m:43s</h4>

                                </div>

                            </div>

                            <div class="swiper match-slider">

                                <div class="swiper-wrapper">

                                    <?php foreach ($daily_picks as $m): ?>
                                        <?php
                                        $profile = $m;
                                        include __DIR__ . '/assets/includes/profile-tile.php';
                                        ?>
                                    <?php endforeach; ?>

                                </div>

                            </div>

                            <div class="content-btn">

                                <a href="daily-matches" class="view-btn">

                                    View All

                                    <i class="fa fa-arrow-right"></i>

                                </a>

                            </div>

                        </section>

                    </div>

                    <!-- ASSISTED PROFILE AD — a main-section promo between the two
                         sliders. Wide rectangular creative to fill the centre column.
                         TODO(backend): swap for a real campaign / ad slot. -->

                    <div class="dashboard-content mb-4">

                        <a href="package" class="ad-unit ad-rect">

                            <span class="ad-label">Sponsored</span>

                            <img src="assets/images/home/matrimony-offer.webp" class="img-fluid"
                                alt="Oppam Assisted Profile — let our experts find your match" width="1400" height="700" loading="lazy" decoding="async">

                        </a>

                    </div>

                    <!-- SECOND CONTENT -->

                    <div class="dashboard-content">

                        <section class="home-content">

                            <div class="content-header">

                                <div class="content-title">

                                    <h2>All Matches(6)</h2>

                                    <p>Profiles matching your preferences</p>

                                </div>



                            </div>

                            <div class="swiper match-slider">

                                <div class="swiper-wrapper">

                                    <?php foreach ($all_matches as $m): ?>
                                        <?php
                                        $profile = $m;
                                        include __DIR__ . '/assets/includes/profile-tile.php';
                                        ?>
                                    <?php endforeach; ?>

                                </div>

                            </div>

                            <div class="content-btn">

                                <a href="all-profiles" class="view-btn">

                                    View All

                                    <i class="fa fa-arrow-right"></i>

                                </a>

                            </div>

                        </section>

                    </div>

                </div>
                <!-- =========================
                ADS RAIL — rightmost column. Shared with all-profiles.php.
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
