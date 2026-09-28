<?php
// MEMBER page — the logged-in member's OWN profile, read-only.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* ---------------------------------------------------------------------------
   DEMO DATA — hardcoded, like every other page in this skeleton.
   TODO(backend): this whole block is the query. Every field below maps 1:1 to a
   control in the profile-creation wizard, and each section's `edit` key points
   at the wizard step that owns it — so the read view and the write view can
   never drift apart. Replace the arrays; the markup underneath does not change.

   A NULL value renders as "Not specified" and is what drives the completeness
   meter — don't substitute an empty string, or the count silently goes wrong.
--------------------------------------------------------------------------- */

$me = [
    'name'    => 'Sally Roberts',
    'id'      => 'VIS446178',
    'tier'    => 'Free Member',
    'photo'   => 'assets/images/home/profile1.webp',
    'age'     => '27yrs',
    'height'  => "5'4\"",
    'study'   => 'MBA',
    'work'    => 'HR Manager',
    'place'   => 'Ernakulam',
    'updated' => 'Updated 3 days ago',

    /* The agent who referred this member, or NULL for a direct signup. Collected
       once on profile-creation.php. It is NOT one of $sections' rows on purpose:
       those drive the completeness meter, and an optional field left blank must
       not nag the member to fill it. */
    'broker'  => 'BRK1042',
    'about'   => 'I am an HR Manager based in Ernakulam, born and brought up in a close-knit '
               . 'family. I enjoy reading, travelling and cooking for the people I care about. '
               . 'I am looking for a partner who values honesty and shares a similar outlook on '
               . 'family life.',
];

// Each section: the wizard step that owns it, and its label => value pairs.
$sections = [
    [
        'title' => 'Basic Details',
        'icon'  => 'fa-user',
        'edit'  => 'profile-creation.php',
        'rows'  => [
            'Name'           => 'Sally Roberts',
            'Date of Birth'  => '12 March 1998',
            'Age'            => '27 years 4 months',
            'Height'         => "5'4\"",
            'Weight'         => '54 kg',
            'Gender'         => 'Female',
            'Marital Status' => 'Never Married',
            'Religion'       => 'Christian',
            'Caste'          => 'RC — Latin',
            'Star'           => 'Uthrattathi',
            'Mother Tongue'  => 'Malayalam',
            'Eating Habits'  => null,
        ],
    ],
    [
        'title' => 'Education &amp; Career',
        'icon'  => 'fa-graduation-cap',
        'edit'  => 'education.php',
        'rows'  => [
            'Highest Education'  => 'MBA — Human Resources',
            'Employed In'        => 'Private Sector',
            'Occupation'         => 'HR Manager',
            'Annual Income'      => '&#8377; 6-8 lakh',
            'Country Living In'  => 'India',
            'Current Location'   => 'Ernakulam, Kerala',
            'Permanent Location' => 'Thrissur, Kerala',
        ],
    ],
    [
        'title' => 'Family Details',
        'icon'  => 'fa-home',
        'edit'  => 'family.php',
        'rows'  => [
            'Father&rsquo;s Name'       => 'Thomas Roberts',
            'Father&rsquo;s Occupation' => 'Retired — Bank Officer',
            'Mother&rsquo;s Name'       => 'Elsy Thomas',
            'Mother&rsquo;s Occupation' => 'Homemaker',
            'Brothers'                  => '1 (married)',
            'Sisters'                   => 'None',
            'Family Status'             => 'Middle Class',
        ],
    ],
    [
        'title' => 'Contact Details',
        'icon'  => 'fa-phone',
        'edit'  => 'contact-details.php',
        'rows'  => [
            'Mobile Number'           => '+91 98470 12345',
            'Alternate Mobile Number' => null,
            'Email Address'           => 'sally.roberts@example.com',
            'Country'                 => 'India',
            'State'                   => 'Kerala',
            'City'                    => 'Ernakulam',
            'Address'                 => 'Kaloor, Ernakulam &ndash; 682017',
        ],
    ],
    [
        'title' => 'Partner Preferences',
        'icon'  => 'fa-heart-o',
        'edit'  => 'partner.php',
        'rows'  => [
            'Age'            => '27 to 33 years',
            'Height'         => "5'6\" to 6'0\"",
            'Marital Status' => 'Never Married',
            'Physical Status'=> 'Normal',
            'Religion'       => 'Christian',
            'Caste'          => 'Any',
            'Education'      => 'Graduate and above',
            'Occupation'     => null,
            'Annual Income'  => null,
        ],
    ],
];

/* Completeness = filled fields / total fields, across every section plus the
   About text. This is what the meter and the "N fields left" nudge report, so
   it is derived, never hand-typed. */
$total = 1;                       // the About paragraph counts as one field
$filled = empty($me['about']) ? 0 : 1;
$missing_steps = [];
foreach ($sections as $s) {
    foreach ($s['rows'] as $v) {
        $total++;
        if ($v === null) {
            $missing_steps[$s['title']] = $s['edit'];
        } else {
            $filled++;
        }
    }
}
$complete = (int) round($filled / $total * 100);

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'My Profile | Oppam Matrimony';
$page_desc     = 'View and manage your Oppam Matrimony profile — basic details, education, family, contact information and partner preferences.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, My Profile, Malayali Matrimony';
$og_desc       = 'View and manage your Oppam Matrimony profile.';
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

    <!-- Reuses the .single-profile surface so the member's own profile and another
         member's profile are visibly the same object. `.is-self` swaps the actions
         (Edit / Manage Photos, not Call / Message) and drops the 190px reservation
         that single-profile.php needs for its absolutely-positioned Shortlist stack. -->
    <section class="single-profile">
        <div class="container">
            <div class="row">
                <div class="col-12">

                    <div class="row">
                        <div class="col-lg-9 col-md-12 col-sm-12 col-12">

                            <!-- ============ HEADER CARD ============ -->
                            <div class="single-detail is-self">
                                <div class="profile-img">
                                    <!-- The member's own photo, at the top of their own page: eager. -->
                                    <img src="<?php ee($me['photo']); ?>" class="img-fluid" alt=""
                                         width="600" height="600" fetchpriority="high" decoding="async">
                                </div>
                                <div class="profile-tittle">

                                    <!-- The page's one and only h1. -->
                                    <h1 class="pf-tt"><?php ee($me['name']); ?></h1>
                                    <p class="pf-dt-n"><?php ee($me['id']); ?><span><?php ee($me['updated']); ?></span></p>

                                    <!-- Referring agent. Rendered only when there is one — a direct
                                         signup shows nothing here rather than an empty "Broker ID:"
                                         label. .text-muted-brand is the existing meta/caption role,
                                         so this needs no new CSS. -->
                                    <?php if (!empty($me['broker'])): ?>
                                        <p class="pf-dt-broker text-muted-brand">
                                            Broker ID: <?php ee($me['broker']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="profile-list">
                                        <ul class="list">
                                            <li class="age"><?php ee($me['age']); ?><span><?php ee($me['height']); ?></span></li>
                                            <li class="study"><?php ee($me['study']); ?>,<span> <?php ee($me['work']); ?></span></li>
                                            <li class="place"><?php ee($me['place']); ?></li>
                                        </ul>

                                        <div class="intst-parent">
                                            <div class="intst-button">
                                                <a class="send-int" href="profile-creation"><i class="fa fa-pencil" aria-hidden="true"></i>Edit Profile</a>
                                                <a class="call-btn" href="profile-photos"><i class="fa fa-camera" aria-hidden="true"></i>Manage Photos</a>
                                                <a class="call-btn" href="single-profile"><i class="fa fa-eye" aria-hidden="true"></i>Preview</a>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <span class="chip self-tier"><?php ee($me['tier']); ?></span>
                            </div>

                            <!-- ============ COMPLETENESS ============ -->
                            <div class="information-table profile-progress">
                                <div class="info-block-head">
                                    <h2>Profile Completeness</h2>
                                    <span class="progress-value"><?php ee($complete); ?>%</span>
                                </div>

                                <!-- Native <progress> rather than a div-and-width fake: it is
                                     announced by screen readers with no ARIA of its own. -->
                                <progress class="progress-bar-native" max="100" value="<?php ee($complete); ?>">
                                    <?php ee($complete); ?>%
                                </progress>

                                <?php if ($missing_steps): ?>
                                    <p class="text-muted-brand progress-note">
                                        <?php ee($total - $filled); ?> field<?php ee(($total - $filled) === 1 ? '' : 's'); ?>
                                        left. Complete profiles get up to 3&times; more responses.
                                    </p>
                                    <p class="progress-links">
                                        <?php foreach ($missing_steps as $label => $href): ?>
                                            <a href="<?php ee(url($href)); ?>">Finish <?php ee($label); ?></a>
                                        <?php endforeach; ?>
                                    </p>
                                <?php else: ?>
                                    <p class="text-muted-brand progress-note">Your profile is complete.</p>
                                <?php endif; ?>
                            </div>

                            <!-- ============ ABOUT ============ -->
                            <div class="information-table">
                                <div class="info-block-head">
                                    <h2><i class="fa fa-quote-left" aria-hidden="true"></i> About Myself</h2>
                                    <a href="profile-creation" class="edit-link">Edit</a>
                                </div>
                                <p class="about-text"><?php ee($me['about']); ?></p>
                            </div>

                            <!-- ============ DETAIL SECTIONS ============ -->
                            <?php foreach ($sections as $s): ?>
                                <?php
                                /* Slug so the header/sidebar can deep-link a single section,
                                   e.g. my-profile.php#partner-preferences. Derived from the
                                   title, never hand-typed — rename a section and the anchor
                                   follows. strip_tags first: titles carry HTML entities. */
                                $anchor = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', strip_tags($s['title'])), '-'));
                                ?>
                                <div class="information-table" id="<?php ee($anchor); ?>">
                                    <div class="info-block-head">
                                        <h2><i class="fa <?php ee($s['icon']); ?>" aria-hidden="true"></i> <?php ee($s['title']); ?></h2>
                                        <a href="<?php ee(url($s['edit'])); ?>" class="edit-link">
                                            Edit<span class="visually-hidden"> <?php ee(strip_tags($s['title'])); ?></span>
                                        </a>
                                    </div>

                                    <!-- A <dl>, not a <table>: these are name/value pairs, not a grid
                                         of data. That matters for layout — .info-grid flows the pairs
                                         into as many columns as the card is wide, which a table's
                                         single label/value row cannot do without breaking its own
                                         semantics via display:grid. -->
                                    <dl class="info-grid">
                                        <?php foreach ($s['rows'] as $label => $value): ?>
                                            <div class="info-pair">
                                                <dt><?php ee($label); ?></dt>
                                                <dd>
                                                    <?php if ($value === null): ?>
                                                        <em>Not specified</em>
                                                    <?php else: ?>
                                                        <?php ee($value); ?>
                                                    <?php endif; ?>
                                                </dd>
                                            </div>
                                        <?php endforeach; ?>
                                    </dl>
                                </div>
                            <?php endforeach; ?>

                        </div>

                        <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                            <?php include_once('assets/includes/ads.php') ?>
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
