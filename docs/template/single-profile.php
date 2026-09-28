<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';
// The .stories rail in the right column renders these — same source as success-stories.php.
require_once __DIR__ . '/assets/includes/stories-data.php';
// The directory this profile sits in — same source all-profiles.php lists from.
require_once __DIR__ . '/assets/includes/profiles-data.php';

/* PREV / NEXT PROFILE.
   The page-nav bar's step buttons walk the directory instead of sitting empty:
   ?id= is the pid of the member being viewed, and profile_neighbours() returns the
   rows either side of it in the SAME order all-profiles.php lists them. No wrap —
   the first has no Prev, the last no Next — and an unknown or missing id leaves
   both null, so the bar falls back to Back only.

   The page body is still hardcoded to Anna Thomas (TODO(backend) below): only the
   arrows are wired to the id yet, so they walk the real sequence the moment the
   body reads it too. */
$viewing = isset($_GET['id']) ? $_GET['id'] : 12370;
$around  = profile_neighbours($viewing);

$page_nav_prev = $around['prev']
    ? ['single-profile.php?id=' . $around['prev']['pid'], $around['prev']['name']]
    : null;
$page_nav_next = $around['next']
    ? ['single-profile.php?id=' . $around['next']['pid'], $around['next']['name']]
    : null;
/* "Member Profile — 2 of 4" while we are inside the list; the plain map title
   otherwise, so a deep link to an unknown id doesn't claim a position. */
if ($around['index']) {
    $page_nav_title = 'Member Profile — ' . $around['index'] . ' of ' . $around['total'];
}

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Verified Matrimony in Kerala | Oppam Matrimony';
$page_desc     = 'View verified Kerala matrimony profile details including profession, education, interests, family background, and preferences.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Matrimony Profile Online';
$og_desc       = 'Explore genuine Kerala matrimony profiles with verified details, compatibility preferences, and meaningful connections.';
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
    <h1 class="visually-hidden">Member Profile</h1>
    <section class="single-profile">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="row">
                        <div class="col-lg-9 col-md-12 col-sm-12 col-12">

                            <!-- The header card sits INSIDE the content column, so it lines up
                                 with the tab panel below it instead of running the full bleed
                                 width of the page. Its typography is the same as a .profiles
                                 result row: .pf-tt name, .pf-dt-n meta line, bulleted facts. -->
                            <div class="single-detail">
                                <div class="profile-img">
                                    <img src="assets/images/matches/600-600-1.webp" class="img-fluid" alt="" width="600" height="600" loading="lazy" decoding="async">
                                </div>
                                <div class="profile-tittle">
                                    <h2 class="pf-tt">Anna Thomas</h2>
                                    <h3 class="pf-dt-n">VIS12370<span>Last seen an hour ago</span></h3>
                                    <div class="profile-list">
                                        <ul class="list">
                                            <li class="age">26 yrs<span>5'0"</span></li>
                                            <li class="study">MBA,<span> Consultant</span></li>
                                            <li class="place">Thrissur</li>
                                        </ul>

                                        <div class="intst-parent">
                                            <div class="intst-button">
                                                <a class="call-btn" href="#"><i class="fa fa-phone"></i>Call now</a>
                                                <a class="send-int" href="#"><i class="fa fa-envelope"></i>Send Message</a>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <div class="icon-parent">
                                    <div class="short-list-sin">
                                        <div class="short-icon">
                                            <i class="fa fa-bookmark"></i>
                                        </div>
                                        <div class="short-content">
                                            <h2>Shortlist</h2>
                                        </div>

                                    </div>
                                    <div class="point-icon">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </div>
                                </div>

                            </div>

                            <div class="information-table">
                                <ul class="nav nav-tabs tab-link-single" id="myTab" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active profile-tab" id="home-tab" data-bs-toggle="tab" data-bs-target="#home" type="button" role="tab" aria-controls="home" aria-selected="true">Personal Information</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link  profile-tab" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="false">Partner Preference</button>
                                    </li>
                                   
                                </ul>
                                <div class="tab-content" id="myTabContent">

                                    <!-- PERSONAL INFORMATION.
                                         Grouped .dtl-basic-info blocks, merged in from the old
                                         details.php (a second, orphaned copy of this same page).
                                         The flat 25-row table that used to be here repeated
                                         Raasi / Horoscope / Religion / Employment twice and had
                                         no headings at all. -->
                                    <div class="tab-pane fade show active" id="home" role="tabpanel" aria-labelledby="home-tab">

                                        <!-- No "About" block here: the page already carries an
                                             About Anna Thomas section below the tabs. -->

                                        <!-- BASIC DETAILS. is-wide: this block carries its own
                                             two-column .new2-row, so it spans the panel rather
                                             than sitting in one half of it. -->
                                        <div class="dtl-basic-info is-wide">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-users" aria-hidden="true"></i>
                                                <h4>Basic Details</h4>
                                            </div>
                                            <div class="row new2-row">
                                                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                                                    <div class="dtl-sec">
                                                        <table class="table tb-basic-info">
                                                            <tr>
                                                                <td class="dtl-profile">Age, Height</td>
                                                                <td class="dtls my-number">26 yrs, 5 ft 0 in</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Date of Birth</td>
                                                                <td class="dtls my-number">15 July 1998</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Marital Status</td>
                                                                <td class="dtls">Never Married</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Physical Status</td>
                                                                <td class="dtls">Normal</td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>
                                                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                                                    <div class="dtl-sec">
                                                        <table class="table tb-basic-info">
                                                            <tr>
                                                                <td class="dtl-profile">Profile Created For</td>
                                                                <td class="dtls">Self</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Lives In</td>
                                                                <td class="dtls">Thrissur, Kerala</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Spoken Languages</td>
                                                                <td class="dtls">Malayalam, English, Tamil</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Eating Habits</td>
                                                                <td class="dtls">Not specified</td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- CONTACT DETAILS -->
                                        <div class="dtl-basic-info">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-address-book" aria-hidden="true"></i>
                                                <h4>Contact Details</h4>
                                            </div>
                                            <div class="dtl-sec">
                                                <table class="table tb-basic-info">
                                                    <tr>
                                                        <td class="dtl-profile">Contact Number</td>
                                                        <td class="dtls my-number">+91 97******** <a href="package">Upgrade</a></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Parent Contact</td>
                                                        <td class="dtls">Not Available</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Chat Status</td>
                                                        <td class="dtls">Online</td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- PROFESSIONAL INFO -->
                                        <div class="dtl-basic-info">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-briefcase" aria-hidden="true"></i>
                                                <h4>Professional Information</h4>
                                            </div>
                                            <div class="dtl-sec">
                                                <table class="table tb-basic-info">
                                                    <tr>
                                                        <td class="dtl-profile">Education</td>
                                                        <td class="dtls">MBA</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Occupation</td>
                                                        <td class="dtls">Consultant</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Employed In</td>
                                                        <td class="dtls">Private Sector</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Annual Income</td>
                                                        <td class="dtls my-number">Rs 3-4 Lakhs</td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- RELIGIOUS INFO -->
                                        <div class="dtl-basic-info">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-star" aria-hidden="true"></i>
                                                <h4>Religious Information</h4>
                                            </div>
                                            <div class="dtl-sec">
                                                <table class="table tb-basic-info">
                                                    <tr>
                                                        <td class="dtl-profile">Religion</td>
                                                        <td class="dtls">Christian</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Caste, Subcaste</td>
                                                        <td class="dtls">RC, Latin</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Star</td>
                                                        <td class="dtls">Uthrattathi</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Raasi</td>
                                                        <td class="dtls">Meenam</td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>

                                    </div>

                                    <!-- PARTNER PREFERENCE.
                                         Both copies of this page shipped a Partner Preference tab
                                         that was a byte-copy of the Personal Information one —
                                         details.php even said so in a "SAME CONTENT" note. These are
                                         the fields the wizard actually collects on partner.php. -->
                                    <div class="tab-pane fade" id="profile" role="tabpanel" aria-labelledby="profile-tab">

                                        <div class="dtl-basic-info is-wide">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-heart" aria-hidden="true"></i>
                                                <h4>What Anna Is Looking For</h4>
                                            </div>
                                            <p>
                                                Looking for a caring and family-oriented partner who values
                                                honesty and mutual respect, and who is settled in his career.
                                            </p>
                                        </div>

                                        <div class="dtl-basic-info is-wide">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-users" aria-hidden="true"></i>
                                                <h4>Basic Preferences</h4>
                                            </div>
                                            <div class="row new2-row">
                                                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                                                    <div class="dtl-sec">
                                                        <table class="table tb-basic-info">
                                                            <tr>
                                                                <td class="dtl-profile">Age</td>
                                                                <td class="dtls my-number">26 - 32 yrs</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Height</td>
                                                                <td class="dtls my-number">5 ft 4 in - 6 ft 0 in</td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>
                                                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                                                    <div class="dtl-sec">
                                                        <table class="table tb-basic-info">
                                                            <tr>
                                                                <td class="dtl-profile">Marital Status</td>
                                                                <td class="dtls">Never Married</td>
                                                            </tr>
                                                            <tr>
                                                                <td class="dtl-profile">Physical Status</td>
                                                                <td class="dtls">Normal</td>
                                                            </tr>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="dtl-basic-info">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-star" aria-hidden="true"></i>
                                                <h4>Religious Preferences</h4>
                                            </div>
                                            <div class="dtl-sec">
                                                <table class="table tb-basic-info">
                                                    <tr>
                                                        <td class="dtl-profile">Religion</td>
                                                        <td class="dtls">Christian</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Caste</td>
                                                        <td class="dtls">Any</td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>

                                        <div class="dtl-basic-info">
                                            <div class="basic-info-hd">
                                                <i class="fa fa-briefcase" aria-hidden="true"></i>
                                                <h4>Professional Preferences</h4>
                                            </div>
                                            <div class="dtl-sec">
                                                <table class="table tb-basic-info">
                                                    <tr>
                                                        <td class="dtl-profile">Education</td>
                                                        <td class="dtls">Graduate or above</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Occupation</td>
                                                        <td class="dtls">Any</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="dtl-profile">Annual Income</td>
                                                        <td class="dtls my-number">Rs 5 Lakhs and above</td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>

                                    </div>

                                </div>

                            </div>

                            <div class="information-table">
                                <div class="personal-information">
                                    <div class="personal-icon">
                                        <i class="fa fa-user" aria-hidden="true"></i>
                                    </div>
                                    <div class="personal-head">
                                        <h2>About Myself</h2>
                                    </div>

                                </div>
                                <div class="about-head">
                                    <h2>About Anna Thomas</h2>
                                    <p>Anna Thomas is a warm and compassionate person known for her positive attitude and strong values. She is admired for her kindness, honesty, and the way she builds meaningful relationships with people around her. Anna approaches life with confidence and determination, balancing responsibilities with grace and dedication. Her friendly nature and caring personality make her someone others feel comfortable with and trust deeply. She values family, friendships, and personal growth, and she always tries to bring happiness and support to those in her life.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                            <div class="advertisement">
                                <div class="advertise-head">
                                    <h2>140+ Matrimony Branches Across India</h2>
                                </div>
                                <div class="advertise-image">
                                    <img src="assets/images/profile/couple.webp" class="img-fluid" alt="" width="1200" height="1200" loading="lazy" decoding="async">
                                </div>
                                <div class="advertise-content">
                                    <p>Visit any of our 140+ branches</p>
                                    <a href="branches">Visit Branches</a>
                                </div>
                            </div>
                            <div class="matrimony-stories">
                                <div class="stories-head">
                                    <h2>Millions of happy marriages</h2>
                                    <p>Matched through oppam matrimony</p>
                                </div>
                                <!-- Was THREE byte-identical slides, all of them "Allen & Riya" on
                                     the same photo, all pointing at href="#". One loop over
                                     stories_data() now — the same source success-stories.php
                                     renders, so the rail and the page can't drift apart, and
                                     "Read their story" lands on that couple's write-up. -->
                                <div class="stories swiper">
                                  <div class="swiper-wrapper">
                                    <?php foreach (stories_data() as $s): ?>
                                        <?php
                                        $story         = $s;
                                        $story_variant = 'slide';
                                        include __DIR__ . '/assets/includes/story-card.php';
                                        ?>
                                    <?php endforeach; ?>
                                  </div><!-- /.swiper-wrapper -->
                                </div><!-- /.stories -->
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>
    <section class="similar-profile-other">
        <div class="container">
            <div class="row g-3">
                <div class="col-12">
                    <div class="similar-head">
                        <h2>Similar Profiles</h2>
                    </div>
                </div>
                <?php
                // Six byte-identical cards, hand-copied, all located in "Landon" — now one
                // loop, matching the Awesome Top Members grid on index.php.
                // The card used to be wrapped in an <a>, which the parser re-parents. It is a
                // plain div now, made clickable by .member-link.
                $similar = [
                    ['img' => 'assets/images/home/profile1.webp', 'name' => 'Reshma', 'place' => 'Landon'],
                    ['img' => 'assets/images/home/profile2.webp', 'name' => 'Anjali', 'place' => 'Landon'],
                    ['img' => 'assets/images/home/profile3.webp', 'name' => 'Ann',    'place' => 'Landon'],
                    ['img' => 'assets/images/home/profile4.webp', 'name' => 'Mariya', 'place' => 'Landon'],
                    ['img' => 'assets/images/home/profile5.webp', 'name' => 'Neethu', 'place' => 'Landon'],
                    ['img' => 'assets/images/home/profile6.webp', 'name' => 'Athira', 'place' => 'Landon'],
                ];
                foreach ($similar as $m): ?>
                    <?php
                    $profile = $m;
                    include __DIR__ . '/assets/includes/member-card.php';
                    ?>
                <?php endforeach; ?>
                <div class="col-12 text-center">
                    <a href="all-profiles" class="view-btn">View All <i class="fa fa-arrow-right"></i></a>
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
