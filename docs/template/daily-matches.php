<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* TODO(backend): demo data. Replace with the real daily-recommendations query.
   Was two byte-identical hand-copied cards ("Anna thomas / VIS12370 / Newyork"). */
$daily = [
    ['name' => 'Anna Thomas',  'id' => 'VIS12370', 'pid' => 12370, 'img' => 'assets/images/matches/profile.webp',   'age' => '26 yrs', 'height' => "5'0\"", 'study' => 'MBA',    'work' => 'Consultant', 'place' => 'Thrissur', 'seen' => 'an hour ago', 'new' => true],
    ['name' => 'Meera Nair',   'id' => 'VIS12384', 'pid' => 12384, 'img' => 'assets/images/matches/600-600-1.webp', 'age' => '25 yrs', 'height' => "5'3\"", 'study' => 'B.Tech', 'work' => 'Engineer',   'place' => 'Kochi',    'seen' => 'today',       'new' => false],
    ['name' => 'Aparna Menon', 'id' => 'VIS12391', 'pid' => 12391, 'img' => 'assets/images/home/profile1.webp',    'age' => '27 yrs', 'height' => "5'2\"", 'study' => 'M.Sc',   'work' => 'Lecturer',    'place' => 'Kozhikode',  'seen' => 'today',        'new' => true],
    ['name' => 'Divya Krishnan','id' => 'VIS12403', 'pid' => 12403,'img' => 'assets/images/home/profile2.webp',    'age' => '24 yrs', 'height' => "5'1\"", 'study' => 'B.Com',  'work' => 'Accountant',  'place' => 'Ernakulam',  'seen' => '3 hours ago',  'new' => false],
    ['name' => 'Sneha Pillai', 'id' => 'VIS12418', 'pid' => 12418, 'img' => 'assets/images/home/profile3.webp',    'age' => '28 yrs', 'height' => "5'4\"", 'study' => 'MBBS',   'work' => 'Doctor',      'place' => 'Thiruvananthapuram', 'seen' => 'yesterday', 'new' => false],
    ['name' => 'Anjali Varma', 'id' => 'VIS12427', 'pid' => 12427, 'img' => 'assets/images/home/profile4.webp',    'age' => '26 yrs', 'height' => "5'3\"", 'study' => 'B.Arch', 'work' => 'Architect',   'place' => 'Kollam',     'seen' => 'an hour ago',  'new' => true],
    ['name' => 'Nithya Raj',   'id' => 'VIS12435', 'pid' => 12435, 'img' => 'assets/images/home/profile5.webp',    'age' => '23 yrs', 'height' => "5'0\"", 'study' => 'B.Sc',   'work' => 'Lab Analyst', 'place' => 'Palakkad',   'seen' => 'today',        'new' => false],
    ['name' => 'Lakshmi Warrier','id'=> 'VIS12442', 'pid' => 12442,'img' => 'assets/images/home/profile6.webp',    'age' => '29 yrs', 'height' => "5'5\"", 'study' => 'LLB',    'work' => 'Advocate',    'place' => 'Alappuzha',  'seen' => '2 days ago',   'new' => false],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Daily Match Recommendations | Oppam Matrimony';
$page_desc     = 'Receive fresh and compatible matrimonial match suggestions every day.';
$page_keywords = 'Daily Matrimony Matches, Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'New Matches Every Day';
$og_desc       = 'Stay updated with personalized daily match recommendations.';
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
    <section class="daily-section bg-coloring">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="d-head">
                        <h1>Daily Matches</h1>
                        <div class="creation-box-img">
                            <img src="assets/images/family/a1.png" alt="" width="238" height="25" loading="lazy" decoding="async">
                        </div>
                    </div>
                </div>
                <div class="col-lg-9 col-md-12 col-sm-12 col-12">

                    <!-- Each card used to be wrapped in <a class="profile-link"> while ALSO
                         containing anchors ("Don't show", "Send Interest", "View profile").
                         An <a> may not contain an <a>: the parser closes the outer one early
                         and re-parents the rest, which is what put profile.php's cards on top
                         of the footer. Same fix as matches.php — a plain <div class="profiles">
                         with .stretched-link on the name, so the whole row is still clickable. -->
                    <!-- .profile-grid is `display: contents` on desktop — the rows stay a flat
                         list exactly as before. Below 768px it becomes a 2-up grid. -->
                    <div class="profile-grid">

                    <?php foreach ($daily as $m): ?>
                        <?php
                        $profile = $m;
                        include __DIR__ . '/assets/includes/profile-row.php';
                        ?>
                    <?php endforeach; ?>

                    </div><!-- /.profile-grid -->

                    <?php $pg_label = 'Daily match pages'; ?>
                    <?php include('assets/includes/pagination.php'); ?>

                </div>

                <!-- ADS RAIL — 3-wide side column. Shared promos from ads.php. -->
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
