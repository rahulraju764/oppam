<?php
// Reachable from BOTH the public and the member chrome, so it is NOT flagged
// $is_public — same treatment as about / package / contact / privacy.
// TODO(backend): once auth is real, this page stays UNGUARDED — it is public.
require_once __DIR__ . '/assets/includes/auth.php';

/* =============================================================================
   TODO(backend): DEMO ADDRESSES. None of these offices is real.

   The advertise card on single-profile.php claims "140+ Matrimony Branches
   Across India" — that number is hardcoded there and here. When the real branch
   list exists, drive both from it; a claim of 140 next to a list of eight is the
   kind of thing a visitor notices.

   And note the existing contradiction this page inherits: contact.php lists
   contact@domain.com / 0895-3881-2873 while footer.php lists a Dubai address and
   support@matrimony.com. Pick one source of truth for contact details and let
   this page read it too.
   ============================================================================= */
$branches = [
    ['city' => 'Thrissur',            'role' => 'Head office',
     'address' => 'Round South, Thrissur, Kerala 680001',
     'phone'   => '0487 244 1100',   'tel' => '+914872441100',
     'hours'   => 'Mon–Sat, 9:30am – 6:00pm'],
    ['city' => 'Kochi',               'role' => 'Regional office',
     'address' => 'MG Road, Ernakulam, Kochi, Kerala 682035',
     'phone'   => '0484 240 1100',   'tel' => '+914842401100',
     'hours'   => 'Mon–Sat, 9:30am – 6:00pm'],
    ['city' => 'Thiruvananthapuram',  'role' => 'Regional office',
     'address' => 'Vazhuthacaud, Thiruvananthapuram, Kerala 695014',
     'phone'   => '0471 233 1100',   'tel' => '+914712331100',
     'hours'   => 'Mon–Sat, 9:30am – 6:00pm'],
    ['city' => 'Kozhikode',           'role' => 'Branch',
     'address' => 'Mavoor Road, Kozhikode, Kerala 673004',
     'phone'   => '0495 276 1100',   'tel' => '+914952761100',
     'hours'   => 'Mon–Sat, 10:00am – 6:00pm'],
    ['city' => 'Kannur',              'role' => 'Branch',
     'address' => 'Fort Road, Kannur, Kerala 670001',
     'phone'   => '0497 270 1100',   'tel' => '+914972701100',
     'hours'   => 'Mon–Sat, 10:00am – 6:00pm'],
    ['city' => 'Kollam',              'role' => 'Branch',
     'address' => 'Chinnakada, Kollam, Kerala 691001',
     'phone'   => '0474 275 1100',   'tel' => '+914742751100',
     'hours'   => 'Mon–Sat, 10:00am – 6:00pm'],
    ['city' => 'Palakkad',            'role' => 'Branch',
     'address' => 'Stadium Bypass Road, Palakkad, Kerala 678014',
     'phone'   => '0491 250 1100',   'tel' => '+914912501100',
     'hours'   => 'Mon–Sat, 10:00am – 6:00pm'],
    ['city' => 'Alappuzha',           'role' => 'Branch',
     'address' => 'Mullackal, Alappuzha, Kerala 688011',
     'phone'   => '0477 226 1100',   'tel' => '+914772261100',
     'hours'   => 'Mon–Sat, 10:00am – 6:00pm'],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Our Branches | Oppam Matrimony';
$page_desc     = 'Visit an Oppam Matrimony office in Thrissur, Kochi, Thiruvananthapuram, Kozhikode and across Kerala.';
$page_keywords = 'Oppam Matrimony Branches, Kerala Matrimony Office, Matrimony Office Thrissur, Matrimony Office Kochi';
$og_title      = 'Visit an Oppam Matrimony Branch';
$og_desc       = 'Our offices across Kerala — addresses, phone numbers and opening hours.';
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

    <section class="branches-section">
        <div class="container is-chrome">

            <div class="section-header">
                <p class="eyebrow">Our Offices</p>
                <h1>Come and talk to us in person</h1>
                <div class="title-divider">
                    <span class="f-line"></span>
                    <span class="icon"><i class="fa fa-heart" aria-hidden="true"></i></span>
                    <span class="line"></span>
                </div>
                <p class="branches-intro">Bring your horoscope and your parents. No appointment needed,
                    though calling ahead saves you a wait on Saturdays.</p>
            </div>

            <!-- g-4, not g-5 — see the note in CLAUDE.md about row gutters overflowing
                 --container-gutter at 360px. -->
            <div class="row g-4">

                <?php foreach ($branches as $b): ?>
                    <div class="col-lg-4 col-md-6 col-12">

                        <!-- <address> is the right element for contact details of the
                             nearest ancestor, which is exactly what this is. -->
                        <div class="branch-card">

                            <div class="branch-card-head">
                                <h2><?php ee($b['city']); ?></h2>
                                <span class="chip"><?php ee($b['role']); ?></span>
                            </div>

                            <address class="branch-card-body">

                                <p class="branch-line">
                                    <i class="fa fa-map-marker" aria-hidden="true"></i>
                                    <span><?php ee($b['address']); ?></span>
                                </p>

                                <p class="branch-line">
                                    <i class="fa fa-phone" aria-hidden="true"></i>
                                    <a href="tel:<?php ee($b['tel']); ?>"><?php ee($b['phone']); ?></a>
                                </p>

                                <p class="branch-line">
                                    <i class="fa fa-clock-o" aria-hidden="true"></i>
                                    <span><?php ee($b['hours']); ?></span>
                                </p>

                            </address>

                        </div>

                    </div>
                <?php endforeach; ?>

            </div>

            <div class="row">
                <div class="col-12 text-center">
                    <p class="branches-more text-muted-brand">
                        Looking for an office not listed here?
                        <a href="<?php ee(url('contact.php')); ?>">Get in touch</a> and we will
                        point you at your nearest one.
                    </p>
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
