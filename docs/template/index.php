<?php
// PUBLIC page — visitor is treated as logged out.
// TODO(backend): delete $is_public once is_logged_in() reads $_SESSION.
$is_public = true;
require_once __DIR__ . '/assets/includes/auth.php';

/* TODO(backend): demo data. Replace with a "recently joined" query.
   Was six hand-copied cards, each one located in "Landon" and carrying an alt that
   named a different person than the card did. */
$members = [
    ['name' => 'Reshma', 'place' => 'Thrissur',            'img' => 'assets/images/home/profile1.webp'],
    ['name' => 'Anjali', 'place' => 'Kochi',               'img' => 'assets/images/home/profile2.webp'],
    ['name' => 'Ann',    'place' => 'Kozhikode',           'img' => 'assets/images/home/profile3.webp'],
    ['name' => 'Mariya', 'place' => 'Ernakulam',           'img' => 'assets/images/home/profile4.webp'],
    ['name' => 'Neethu', 'place' => 'Trivandrum',          'img' => 'assets/images/home/profile5.webp'],
    ['name' => 'Athira', 'place' => 'Kannur',              'img' => 'assets/images/home/profile6.webp'],
];

/* TODO(backend): demo data. Replace with a success-stories query.
   Every card used to say "( Business )" under the name. */
$testimonials = [
    ['name' => 'Devika',   'place' => 'Thrissur',   'img' => 'assets/images/home/webp-women-01.webp',
     'quote' => 'Verified profiles and simple messaging made the search stress-free. I found the right person without the noise.'],
    ['name' => 'Martha',   'place' => 'Kochi',      'img' => 'assets/images/home/webp-women-02.webp',
     'quote' => 'The matches suggested to me actually fit. Within weeks I met someone who shared my values and my goals.'],
    ['name' => 'Meera',    'place' => 'Kollam',     'img' => 'assets/images/home/webp-women-03.webp',
     'quote' => 'From registration to the first meeting, the whole thing was smooth. Our families were involved from early on.'],
    ['name' => 'Sreya',    'place' => 'Kozhikode',  'img' => 'assets/images/home/webp-women-04.webp',
     'quote' => 'Genuine profiles and a team that answers. It felt safe to reach out, which is the part I was most worried about.'],
    ['name' => 'Aparna',   'place' => 'Ernakulam',  'img' => 'assets/images/home/profile7.webp',
     'quote' => 'Daily recommendations meant I was never scrolling for hours. Ten minutes a day was enough to find him.'],
    ['name' => 'Lakshmi',  'place' => 'Kannur',     'img' => 'assets/images/home/profile8.webp',
     'quote' => 'My parents trusted it because every profile is checked. That mattered more to us than anything else.'],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Oppam Matrimony | Trusted Kerala Matrimony';
$page_desc     = 'Oppam Matrimony helps Malayalis in Kerala to find genuine life partners through verified profiles, secure matchmaking, and trusted connections.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms, Online Matrimony Kerala, Marriage Portal Kerala, Trusted Matrimony Kerala';
$og_title      = 'Find Genuine Kerala Matches with Oppam Matrimony';
$og_desc       = 'Join Oppam Matrimony and discover verified Kerala bride and groom profiles through secure matchmaking and trusted matrimony services.';
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

    <!-- hero: carousel + register form -->
    <section class="matrimony-slides">
        <h1 class="visually-hidden">Oppam Matrimony — trusted Kerala matchmaking</h1>

        <!-- Swiper, not Owl. The dots are rendered by renderBullet() in custom.js from
             the slide images themselves, so the old data-dot="<img …>" attribute on
             every slide is gone — the images were listed twice, once in the slide and
             once in an HTML-in-an-attribute string. -->
        <div class="basement-carousel swiper">
            <div class="swiper-wrapper">
            <div class="basement-content swiper-slide">

                <div class="basement-images">
                    <img src="assets/images/banner/banner1.webp" class="img-fluid" alt="" width="1920" height="700" fetchpriority="high" decoding="async">
                </div>

                <div class="basement-parent">
                    <div class="basement-details">
                        <h2>Join Today</h2>
                        <p>Begin your journey toward a meaningful relationship with trusted and verified matrimonial profiles.</p>
                    </div>
                </div>

            </div>
            <div class="basement-content swiper-slide">

                <div class="basement-images">
                    <img src="assets/images/banner/banner2.webp" class="img-fluid" alt="" width="1920" height="700" loading="lazy" decoding="async">
                </div>

                <div class="basement-parent">
                    <div class="basement-details">
                        <h2>Find Matches</h2>
                        <p>Discover compatible matches based on your preferences, values, and life aspirations.</p>
                    </div>
                </div>

            </div>
            <div class="basement-content swiper-slide">

                <div class="basement-images">
                    <img src="assets/images/banner/banner3.webp" class="img-fluid" alt="" width="1920" height="700" loading="lazy" decoding="async">
                </div>

                <div class="basement-parent">
                    <div class="basement-details">
                        <h2>Connect Securely</h2>
                        <p>Communicate safely and confidently with genuine members through secure interactions.</p>
                    </div>
                </div>

            </div>

            <div class="basement-content swiper-slide">

                <div class="basement-images">
                    <img src="assets/images/banner/banner4.webp" class="img-fluid" alt="" width="1920" height="700" loading="lazy" decoding="async">
                </div>

                <div class="basement-parent">
                    <div class="basement-details">
                        <h2>Start Forever</h2>
                        <p>Take the next step towards a happy marriage and build a lifetime of togetherness.</p>
                    </div>
                </div>

            </div>
            </div><!-- /.swiper-wrapper -->

            <!-- Custom markup inside the buttons: the <i> is what the existing
                 hover-thumbnail styling hangs off. Swiper draws its own arrow via
                 ::after, which style.css suppresses. -->
            <button class="swiper-button-prev" type="button" aria-label="Previous slide"><span><i class="fa fa-angle-left" aria-hidden="true"></i></span></button>
            <button class="swiper-button-next" type="button" aria-label="Next slide"><span><i class="fa fa-angle-right" aria-hidden="true"></i></span></button>

            <div class="swiper-pagination"></div>

        </div>

        <!-- The form used to be `position:absolute; left:30px` on the section, so it hung off
             the viewport edge and was dragged back into place by a stack of `left: 18%/8%/1.5%`
             media queries. It now rides a layer that shares the page container, so it lines up
             with the navbar; below 992px the layer goes static and the form flows under the
             carousel instead of covering it. -->
        <div class="hero-form-layer">
            <div class="container is-chrome">
                <!-- TODO(backend): this form has no handler. Handle $_POST in register.php
                     (create the user, start the session), then redirect to profile-creation.php. -->
                <form class="register-form" method="post" action="register">
                    <?php csrf_field(); ?>

                    <div class="register-head">
                        <p class="eyebrow">Official matrimony service</p>
                        <h2>Create your free profile</h2>
                    </div>

                    <!-- The labels for first name / email / password are visually-hidden, not
                         absent: the placeholder already says the same word, and the hero form
                         has to fit inside the 650px banner. Screen readers still get them. -->
                    <div class="form-row">
                        <label class="form-label visually-hidden" for="reg-first-name">First name</label>
                        <input type="text" class="form-control mat-register" id="reg-first-name" name="first_name" placeholder="First name" required>
                    </div>

                    <fieldset class="form-row gender-head">
                        <legend class="form-label">Gender</legend>
                        <div class="gender-options">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender" id="male" value="male" required>
                                <label class="form-check-label" for="male">Male</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender" id="female" value="female">
                                <label class="form-check-label" for="female">Female</label>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="form-row">
                        <legend class="form-label">Date of birth</legend>
                        <div class="row g-2">
                            <div class="col-4">
                                <input type="text" class="form-control mat-register" id="reg-dob-day" name="dob_day" placeholder="DD" aria-label="Date of birth: day" inputmode="numeric" maxlength="2" required>
                            </div>
                            <div class="col-4">
                                <input type="text" class="form-control mat-register" id="reg-dob-month" name="dob_month" placeholder="MM" aria-label="Date of birth: month" inputmode="numeric" maxlength="2" required>
                            </div>
                            <div class="col-4">
                                <input type="text" class="form-control mat-register" id="reg-dob-year" name="dob_year" placeholder="YYYY" aria-label="Date of birth: year" inputmode="numeric" maxlength="4" required>
                            </div>
                        </div>
                    </fieldset>

                    <div class="form-row">
                        <label class="form-label visually-hidden" for="reg-email">Email</label>
                        <input type="email" class="form-control mat-register" id="reg-email" name="email" placeholder="you@example.com" required>
                    </div>

                    <fieldset class="form-row">
                        <legend class="form-label">Mobile number</legend>
                        <div class="row g-2">
                            <div class="col-5">
                                <select class="form-select mat-register" id="reg-country-code" name="country_code" aria-label="Country code">
                                    <option value="91" selected>India [+91]</option>
                                    <option value="971">UAE [+971]</option>
                                    <option value="966">Saudi Arabia [+966]</option>
                                    <option value="44">UK [+44]</option>
                                    <option value="1">USA [+1]</option>
                                </select>
                            </div>
                            <div class="col-7">
                                <input type="tel" class="form-control mat-register" id="reg-mobile" name="mobile" placeholder="Mobile number" aria-label="Mobile number" required>
                            </div>
                        </div>
                    </fieldset>

                    <div class="form-row">
                        <label class="form-label visually-hidden" for="reg-password">Password</label>
                        <input type="password" class="form-control mat-register" id="reg-password" name="password" placeholder="Password" required>
                    </div>

                    <div class="form-check tick-box">
                        <input class="form-check-input" type="checkbox" id="reg-terms" name="terms" value="1" required>
                        <label class="form-check-label" for="reg-terms">
                            <!-- Two documents, two links. This was one anchor labelled
                                 "Terms of Use & Privacy Policy" pointing only at privacy —
                                 half of what it named did not exist. terms.php does now. -->
                            I have read and agreed to the <a href="terms">Terms of Use</a>
                            and <a href="privacy">Privacy Policy</a>
                        </label>
                    </div>

                    <!-- TODO(backend): restore <button type="submit"> once register.php has a handler.
                         It's an anchor so the static skeleton can navigate. -->
                    <div class="regi-button">
                        <a href="register">Create an account for free</a>
                    </div>

                    <p class="account">Already have an account? <a href="login">Login</a></p>
                </form>
            </div>
        </div>
    </section>

    <!-- about -->
    <section class="about-section">
        <div class="container is-chrome">
            <!-- g-4, not g-5: at 360px the container's gutter is 16px, and a g-5 row's -24px
                 negative margin punches 8px past it — a horizontal scrollbar on every phone. -->
            <div class="row align-items-center g-4">

                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-image">
                        <img src="assets/images/home/oppam-proposal-01-800-700.webp" alt="" class="img-fluid" width="800" height="700" loading="lazy" decoding="async">
                        <div class="video-icon">
                            <i class="fa fa-play" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>

                <!-- The copy used to live in an `.about-content` box that was `position:absolute;
                     right:-90%` on top of the photo — i.e. pushed into the next column by a
                     negative offset, then patched at every breakpoint. It is now simply the
                     second column. -->
                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-content">
                        <p class="eyebrow">About Oppam</p>
                        <h2>Love Can Happen Anywhere, Anytime</h2>
                        <p>Oppam brings Malayali families together with verified profiles, private conversations and
                            matchmaking you can trust — from Thrissur to Thiruvananthapuram.</p>
                        <a href="about" class="about-btn">Read More</a>

                        <div class="about-stats">
                            <div class="stat-tile">
                                <div class="stat-icon">
                                    <img src="assets/images/home/stat-downloads.png" class="img-fluid" alt="" width="128" height="128" loading="lazy" decoding="async">
                                </div>
                                <h3><span class="counter" data-target="105">0</span> K<sup>+</sup></h3>
                                <p class="text-muted-brand">Downloaded App</p>
                            </div>
                            <div class="stat-tile">
                                <div class="stat-icon">
                                    <img src="assets/images/about/heart.png" class="img-fluid" alt="" width="128" height="128" loading="lazy" decoding="async">
                                </div>
                                <h3><span class="counter" data-target="90">0</span> %</h3>
                                <p class="text-muted-brand">Successful Marriages</p>
                            </div>
                            <div class="stat-tile">
                                <div class="stat-icon">
                                    <img src="assets/images/about/computing.png" class="img-fluid" alt="" width="128" height="128" loading="lazy" decoding="async">
                                </div>
                                <h3><span class="counter" data-target="50">0</span> K<sup>+</sup></h3>
                                <p class="text-muted-brand">Verified Members</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>
    <!-- about -->

    

    <!-- work-section -->
    <section class="work-section">
        <div class="container is-chrome">
            <div class="section-header">
                <h4 class="theme-color">How Does It Work?</h4>
                <h2>You’re Just 3 Steps Away From the Right Match</h2>
                <div class="title-divider">
                    <span class="f-line"></span>
                    <span class="icon"><i class="fa fa-heart"></i></span>
                    <span class="line"></span>
                </div>
            </div>
            <!-- The three steps used to be spread across a full-bleed container, so on a wide
                 screen they sat metres apart. The wrapper caps them. -->
            <div class="section-wrapper">
                <div class="row justify-content-center g-4">
                    <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                        <div class="lab-item">
                            <div class="lab-inner text-center">
                                <div class="lab-thumb">
                                    <div class="thumb-inner">
                                        <img src="assets/images/home/01.png" alt="" width="144" height="144" loading="lazy" decoding="async">
                                        <div class="step">
                                            <span>step</span>
                                            <p>01</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="lab-content">
                                    <h4>Create A Profile</h4>
                                    <p>Tell us about yourself, your family and what matters to you. It takes a few minutes.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                        <div class="lab-item">
                            <div class="lab-inner text-center">
                                <div class="lab-thumb">
                                    <div class="thumb-inner">
                                        <img src="assets/images/home/02.png" alt="" width="144" height="144" loading="lazy" decoding="async">
                                        <div class="step">
                                            <span>step</span>
                                            <p>02</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="lab-content">
                                    <h4>Find Matches</h4>
                                    <p>We surface compatible profiles daily, filtered by the preferences you actually care about.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                        <div class="lab-item">
                            <div class="lab-inner text-center">
                                <div class="lab-thumb">
                                    <div class="thumb-inner">
                                        <img src="assets/images/home/03.png" alt="" width="144" height="144" loading="lazy" decoding="async">
                                        <div class="step">
                                            <span>step</span>
                                            <p>03</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="lab-content">
                                    <h4>Connect &amp; Meet</h4>
                                    <p>Send an interest, chat privately, and involve your families when you’re both ready.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- work-section -->

    <!-- members -->
    <section class="members-section">
        <div class="container is-chrome">
            <div class="row">
                <div class="col-12 text-center">
                    <div class="section-title">
                        <h2>Awesome <span>Top</span> Members</h2>
                        <div class="title-divider">
                            <span class="f-line"></span>
                            <span class="icon"><i class="fa fa-heart"></i></span>
                            <span class="line"></span>
                        </div>
                        <p>Every profile is <span class="highlight">verified</span> before it goes live — real people, real families,
                            genuinely looking for a life partner across Kerala.</p>
                    </div>
                </div>
            </div>

            <!-- Six byte-identical cards, hand-copied, every one of them located in
                 "Landon". Now one loop over $members.

                 The card used to be wrapped in an <a>, which the parser re-parents. It is a
                 plain div now, made clickable by .member-link — one absolutely positioned
                 anchor over the whole card, carrying the accessible name. -->
            <div class="row g-3">
                <?php foreach ($members as $m): ?>
     <?php
     $profile = $m;
     include __DIR__ . '/assets/includes/member-card.php';
     ?>
 <?php endforeach; ?>
            </div>

            <div class="row">
                <div class="col-12 text-center">
                    <!-- Public page: a logged-out visitor cannot browse members, so this
                         is the conversion CTA, not a link into a member listing. It used
                         to point at profile.php (a member match-list page). -->
                    <a href="register" class="view-btn">View All Members <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>
    <!-- members -->

    <!-- subscription -->
    <!-- The .container and .row here were never closed, so the testimonials, the members
         section and both footers were all being parsed INSIDE the pricing row. -->
    <section class="subscription-section">
        <div class="container is-chrome">
            <div class="row">
                <div class="col-12">
                    <div class="subscription-content">
                        <p class="eyebrow">Membership Plans</p>
                        <h2>Choose the plan that suits you</h2>
                        <p>Upgrade to view verified mobile numbers, chat directly with families, and get your profile
                            shown to more matches across Kerala.</p>
                    </div>
                </div>
            </div>

            <!-- TODO(backend): plan names, prices and feature lists below are hardcoded demo data.
                 Keep them in sync with package.php until both read from one source. -->
            <?php $plan_cta_url = 'package.php'; ?>
            <?php include 'assets/includes/pricing-cards.php'; ?>
        </div>
    </section>
    <!-- subscription -->

    <!-- testimonial-section -->
    <section class="testimonial-section">
        <div class="container is-chrome">

            <div class="section-header">
                <h4 class="theme-color">Testimonials</h4>
                <h2 class="script-accent">Stories of Trust &amp; Happiness</h2>
                <div class="title-divider">
                    <span class="f-line"></span>
                    <span class="icon"><i class="fa fa-heart"></i></span>
                    <span class="line"></span>
                </div>
            </div>

            <div class="testimonial-carousel swiper">
                <div class="swiper-wrapper">
                <?php foreach ($testimonials as $t): ?>
                        <div class="testimonial-card swiper-slide">
                        <div class="testimonial-img">
                            <!-- 600x600: every $testimonials photo is a square home/*.webp. -->
                            <img src="<?php ee($t['img']); ?>" alt=""
                                 width="600" height="600" loading="lazy" decoding="async">
                        </div>
                        <div class="testimonial-content">
                            <h4><?php ee($t['name']); ?></h4>
                            <span><?php ee($t['place']); ?></span>
                            <p>“<?php ee($t['quote']); ?>”</p>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div><!-- /.swiper-wrapper -->

                <div class="swiper-pagination"></div>
            </div>

        </div>
    </section>
    <!-- testimonial-section -->

    

    </main>

    <?php include_once('assets/includes/footer.php') ?>
    <?php include_once('assets/includes/footer2.php') ?>
    <?php include_once('assets/includes/script.php') ?>
</body>

</html>
