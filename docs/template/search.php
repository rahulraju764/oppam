<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* Option lists for the search form. Presentational only — no DB.
   TODO(backend): the name= on each control is the query key to read. */
$ages    = range(18, 60);

$heights = [];
for ($in = 54; $in <= 78; $in++) {           // 4'6" .. 6'6"
    $heights[$in] = sprintf("%d'%d\" (%dcm)", intdiv($in, 12), $in % 12, round($in * 2.54));
}

/* "Late Marriage" replaces the old "Awaiting Divorce" option, and is the ONE place
   late marriage is expressed — a separate "Late Matrimony" checkbox lived here
   briefly and was dropped as a duplicate of this value. Note the wizard's own marital
   lists (profile-creation.php, partner.php) never offered "Awaiting Divorce" — they
   run Unmarried / Divorced / Separated / Widow-Widower — so nothing there needed the
   same edit. */
$marital     = ['Any', 'Never Married', 'Divorced', 'Widowed', 'Late Marriage'];
$religions   = ['Any', 'Hindu', 'Christian', 'Muslim', 'Jain', 'Sikh', 'Buddhist', 'Other'];
/* TODO(backend): caste is religion-dependent — this flat Kerala list is a
   placeholder. Repopulate it from the religion the user picked. */
$castes      = ['Any', 'Nair', 'Ezhava / Thiyya', 'Menon', 'Namboothiri', 'Viswakarma', 'Syrian Catholic', 'Latin Catholic', 'Jacobite', 'Marthoma', 'Orthodox', 'Pentecostal', 'Sunni', 'Mappila', 'Other'];
$educations  = ['Any', 'Higher Secondary', 'Bachelors', 'Masters', 'Doctorate', 'Diploma', 'Other'];
$professions = ['Any', 'Doctor', 'Engineer', 'IT Professional', 'Teacher', 'Business', 'Government Service', 'Banking / Finance', 'Nurse', 'Other'];
$districts   = ['Any', 'Thiruvananthapuram', 'Kollam', 'Pathanamthitta', 'Alappuzha', 'Kottayam', 'Idukki', 'Ernakulam', 'Thrissur', 'Palakkad', 'Malappuram', 'Kozhikode', 'Wayanad', 'Kannur', 'Kasaragod', 'Outside Kerala'];

/* Small helper so a <select> is one line instead of a five-line foreach.
   $keys = true keeps the array key as the value attribute (used for height). */
function options(array $list, $selected = null, bool $keys = false)
{
    $out = '';
    foreach ($list as $k => $label) {
        $value = $keys ? $k : $label;
        $isSel = ($selected !== null && $value == $selected) ? ' selected' : '';
        $out  .= '<option value="' . e($value) . '"' . $isSel . '>' . e($label) . '</option>';
    }
    return $out;
}

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Search Kerala Bride & Groom Profiles | Oppam Matrimony';
$page_desc     = 'Browse verified Kerala matrimony profiles by religion, district, profession, age, and preferences using smart search filters.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Search Verified Kerala Matrimony Profiles Online';
$og_desc       = 'Explore genuine Kerala bride and groom profiles with advanced search filters and trusted matchmaking on Oppam Matrimony.';
$page_robots   = 'index, follow';

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
        SEARCH — faceted 3-column layout:
        filters (col-3) | results (col-6) | stories & ads (col-3)
        Card surfaces are the shared dashboard.php shells.
    ========================= -->

    <section class="dashboard-section search-section" data-mobile-rail data-rail-label="Filters">

        <div class="container">

            <div class="row dashboard-row">

                <!-- =========================
                    LEFT RAIL — FILTERS
                    Sticky on desktop so refining a search no longer means
                    scrolling back up past the results.
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-content filter-card" data-rail="menu">

                        <section class="home-content">

                            <div class="content-header">

                                <div class="content-title">
                                    <h2>Filters</h2>
                                </div>

                            </div>

                            <!-- ===== FILTERS =====
                                 One form. This used to be two tab panes ("Regular" /
                                 "Advanced") that repeated the same five fields, so every
                                 control existed twice with two sets of ids. The extra
                                 fields now live behind "More filters" instead.
                                 TODO(backend): no handler yet — read these GET keys. -->

                            <!-- ===== SEARCH BY PROFILE ID =====
                                 Its own form, not a field of the filter form below.
                                 An ID lookup is not a filter: it either resolves to one
                                 profile or to nothing, and combining it with age / caste /
                                 religion can only ever contradict it. Submitting the
                                 filter form with an ID in it would have carried a dozen
                                 irrelevant GET keys along.
                                 TODO(backend): resolve to single-profile.php?id=... and
                                 show a "no member with that ID" message on a miss. -->

                            <form class="id-search" action="search" method="get" role="search">

                                <label class="form-label" for="profile-id">Search by Profile ID</label>

                                <div class="id-search-row">

                                    <input type="search" class="form-control profile-select" id="profile-id"
                                        name="profile_id" placeholder="e.g. VIS446178"
                                        autocomplete="off" spellcheck="false">

                                    <button type="submit" class="id-search-btn" aria-label="Find profile by ID">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                    </button>

                                </div>

                                <small class="text-muted-brand">Know the ID? Go straight to the profile.</small>

                            </form>

                            <p class="id-search-sep"><span>or refine a search</span></p>

                            <form class="search-form" action="search" method="get">

                                <!-- LOOKING FOR — segmented pills, not loose radios -->

                                <div class="filter-row">

                                    <span class="form-label" id="looking-for-label">I'm looking for</span>

                                    <div class="segmented" role="radiogroup" aria-labelledby="looking-for-label">

                                        <input type="radio" name="gender" value="female" id="g-bride" checked>
                                        <label for="g-bride">
                                            <i class="fa fa-female" aria-hidden="true"></i>
                                            A Bride
                                        </label>

                                        <input type="radio" name="gender" value="male" id="g-groom">
                                        <label for="g-groom">
                                            <i class="fa fa-male" aria-hidden="true"></i>
                                            A Groom
                                        </label>

                                    </div>

                                </div>


                                <!-- CORE FILTERS -->

                                <div class="row g-4">

                                    <div class="col-sm-6 col-lg-12">

                                        <label class="form-label" for="age-min">Age</label>

                                        <div class="range-row">

                                            <select class="form-select profile-select" id="age-min" name="age_min">
                                                <?php echo options($ages, 21); ?>
                                            </select>

                                            <span class="range-sep">to</span>

                                            <select class="form-select profile-select" id="age-max" name="age_max" aria-label="Maximum age">
                                                <?php echo options($ages, 30); ?>
                                            </select>

                                        </div>

                                    </div>

                                    <div class="col-sm-6 col-lg-12">

                                        <label class="form-label" for="height-min">Height</label>

                                        <div class="range-row">

                                            <select class="form-select profile-select" id="height-min" name="height_min">
                                                <?php echo options($heights, 54, true); ?>
                                            </select>

                                            <span class="range-sep">to</span>

                                            <select class="form-select profile-select" id="height-max" name="height_max" aria-label="Maximum height">
                                                <?php echo options($heights, 78, true); ?>
                                            </select>

                                        </div>

                                    </div>

                                    <div class="col-sm-6 col-lg-12">

                                        <label class="form-label" for="marital">Marital Status</label>

                                        <select class="form-select profile-select" id="marital" name="marital">
                                            <?php echo options($marital); ?>
                                        </select>

                                    </div>

                                    <div class="col-sm-6 col-lg-12">

                                        <label class="form-label" for="religion">Religion</label>

                                        <select class="form-select profile-select" id="religion" name="religion">
                                            <?php echo options($religions); ?>
                                        </select>

                                    </div>

                                    <div class="col-sm-6 col-lg-12">

                                        <label class="form-label" for="caste">Caste</label>

                                        <select class="form-select profile-select" id="caste" name="caste">
                                            <?php echo options($castes); ?>
                                        </select>

                                    </div>

                                </div>


                                <!-- MORE FILTERS — replaces the old "Advanced Search" tab -->

                                <button class="more-filters-toggle collapsed"
                                    type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#more-filters"
                                    aria-expanded="false"
                                    aria-controls="more-filters">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <span class="more-filters-label">More filters</span>
                                    <i class="fa fa-angle-down more-filters-caret" aria-hidden="true"></i>
                                </button>

                                <div class="collapse" id="more-filters">

                                    <div class="row g-4 more-filters-grid">

                                        <div class="col-sm-6 col-lg-12">

                                            <label class="form-label" for="district">District</label>

                                            <select class="form-select profile-select" id="district" name="district">
                                                <?php echo options($districts); ?>
                                            </select>

                                        </div>

                                        <div class="col-sm-6 col-lg-12">

                                            <label class="form-label" for="education">Education</label>

                                            <select class="form-select profile-select" id="education" name="education">
                                                <?php echo options($educations); ?>
                                            </select>

                                        </div>

                                        <div class="col-sm-6 col-lg-12">

                                            <label class="form-label" for="profession">Profession</label>

                                            <select class="form-select profile-select" id="profession" name="profession">
                                                <?php echo options($professions); ?>
                                            </select>

                                        </div>

<?php /* Member ID used to be a filter field here. It is now the dedicated
                                             "Search by Profile ID" form at the TOP of this rail — an exact
                                             lookup, not something to combine with age/caste. Don't add it back. */ ?>

                                    </div>

                                </div>


                                <!-- ACTIONS -->

                                <div class="filter-actions">

                                    <div class="profile-btn">
                                        <button type="submit">
                                            <i class="fa fa-search" aria-hidden="true"></i>
                                            Search
                                        </button>
                                    </div>

                                    <button type="reset" class="filter-reset">Reset filters</button>

                                </div>

                            </form>

                        </section>

                    </div>

                </div>


                <!-- =========================
                    CENTRE — RESULTS
                ========================= -->
                <!-- TODO(backend): hardcoded demo results; count below is static too. -->

                <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-content search-results">

                        <section class="home-content">

                            <div class="content-header">

                                <div class="content-title">
                                    <h1>Search Results</h1>
                                    <p>3 profiles match your filters</p>
                                </div>

                            </div>

                            <?php
                            $results = [
                                ['name' => 'Anna Thomas',   'id' => 'VIS12370', 'pid' => 12370, 'img' => 'assets/images/matches/profile.webp',   'age' => '26yrs', 'height' => "5'0\"", 'study' => 'MBA,', 'work' => 'Consultant', 'place' => 'Thrissur', 'new' => true],
                                ['name' => 'Meera Nair',    'id' => 'VIS12384', 'pid' => 12384, 'img' => 'assets/images/matches/600-600-1.webp', 'age' => '25yrs', 'height' => "5'3\"", 'study' => 'B.Tech,', 'work' => 'Engineer', 'place' => 'Kochi', 'new' => false],
                                ['name' => 'Divya Krishna', 'id' => 'VIS12391', 'pid' => 12391, 'img' => 'assets/images/matches/profile.webp',   'age' => '28yrs', 'height' => "5'2\"", 'study' => 'MBBS,', 'work' => 'Doctor', 'place' => 'Kozhikode', 'new' => false],
                            ];
                            ?>

                            <!-- .profile-grid is `display: contents` on desktop — the rows stay a flat
                                 list exactly as before. Below 768px it becomes a 2-up grid. -->
                            <div class="profile-grid">

                            <?php foreach ($results as $r): ?>
                                <?php
                                $profile = $r;
                                $profile['meta'] = 'Last seen an hour ago';
                                include __DIR__ . '/assets/includes/profile-row.php';
                                ?>
                            <?php endforeach; ?>

                            </div><!-- /.profile-grid -->

                            <div class="content-btn">
                                <a href="all-profiles" class="view-btn">View All Profiles</a>
                            </div>

                        </section>

                    </div>

                    <!-- Outside the content card, in the column — as on every other listing page. -->
                    <?php $pg_label = 'Search result pages'; ?>
                    <?php include('assets/includes/pagination.php'); ?>

                </div>


                <!-- =========================
                    RIGHT RAIL — Success Stories / interested member / ads.
                    Sits after the content in the DOM so a phone gets the
                    results before the ads.
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <div class="search-rail">

                        <!-- Was unlinked — same as the card on my-matches.php. It used to
                             point at details.php, which was never a success-stories page
                             but a second copy of the member profile detail view; that page
                             has been merged into single-profile.php and removed.
                             success-stories.php now exists, so both cards link to it. -->
                        <div class="success-stories" data-rail="weave">
                            <div class="success-img">
                                <img src="assets/images/details/s1.jpg" class="img-fluid" alt="" width="400" height="400" loading="lazy" decoding="async">
                                <div class="success-content">
                                    <h2 class="script-accent"><a href="success-stories">Success Stories</a></h2>
                                    <p>Thanks to Oppam Matrimony we found each other and now got married. All thanks to the team.</p>
                                </div>
                            </div>
                        </div>

                        <!-- TODO(backend): hardcoded demo member. -->
                        <div class="success-one" data-rail="weave">
                            <div class="success-one-head">
                                <h2>She is Interested</h2>
                            </div>
                            <div class="success-detail">
                                <div class="success-image">
                                    <img src="assets/images/details/001.jpg" alt="" width="600" height="600" loading="lazy" decoding="async">
                                </div>
                                <div class="success-one-pgh">
                                    <h2>Cristina M</h2>
                                    <p class="pf-id">V61132U</p>
                                    <ul class="dt-list">
                                        <li>24 yrs, 5ft 4in</li>
                                        <li>Thrissur</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- The action sits BELOW the photo/detail row, as a full-width block —
                                 same layout as the Interest Received card's .my-intrest-btn on
                                 my-matches.php. Nested inside .success-one-pgh it was squeezed into
                                 the narrow column beside the 96px photo. -->
                            <div class="success-btn">
                                <a href="single-profile"><i class="fa fa-heart" aria-hidden="true"></i>Connect</a>
                            </div>
                        </div>

                        <div class="ad-oppam swiper" data-rail="weave">

                            <div class="swiper-wrapper">
                                <div class="oppam-ad swiper-slide">
                                    <div class="oppam-img">
                                        <img src="assets/images/search/ad1.jpg" alt="" width="800" height="800" loading="lazy" decoding="async">
                                    </div>
                                </div>
                                <div class="oppam-ad swiper-slide">
                                    <div class="oppam-img">
                                        <img src="assets/images/search/ad1.jpg" alt="" width="800" height="800" loading="lazy" decoding="async">
                                    </div>
                                </div>
                            </div>

                            <div class="swiper-pagination"></div>

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
