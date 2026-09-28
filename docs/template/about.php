<?php
// Reachable from BOTH the public and the member chrome, so it is NOT flagged
// $is_public — same treatment as package / contact / privacy / success-stories.
// It is indexable in its own right (see $page_robots below).
// TODO(backend): once auth is real, this page stays UNGUARDED — it is public.
require_once __DIR__ . '/assets/includes/auth.php';

/* TODO(backend): demo copy and demo numbers. The three counters here are the SAME
   three that index.php's about band animates (105K downloads / 90% / 50K verified)
   — they are hardcoded in both places. When they come from a query, read them once
   and render both from it. */
$values = [
    ['icon' => 'fa-shield',      'title' => 'Every profile is verified',
     'text' => 'A profile does not go live until we have checked it. That is the whole reason families trust the site, and it is not negotiable.'],
    ['icon' => 'fa-lock',        'title' => 'Your details stay yours',
     'text' => 'Contact details are never shown on a public profile. You decide who sees them, and you can change your mind.'],
    ['icon' => 'fa-users',       'title' => 'Families, not just individuals',
     'text' => 'A Kerala marriage involves two families. The site is built for that — parents can be part of the conversation from the start.'],
    ['icon' => 'fa-map-marker',  'title' => 'Kerala first',
     'text' => 'Community, language, district and horoscope are first-class fields here, not an afterthought bolted onto a national site.'],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'About Us | Oppam Matrimony';
$page_desc     = 'Oppam Matrimony is a Kerala matrimony service built around verified profiles, family involvement and privacy. Here is who we are and how we work.';
$page_keywords = 'About Oppam Matrimony, Kerala Matrimony, Malayali Matrimony, Trusted Matrimony Kerala';
$og_title      = 'About Oppam Matrimony';
$og_desc       = 'A Kerala matrimony service built on verified profiles and family trust.';
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

    <!-- /*================ INTRO ================*/
         Every section is .container is-chrome, like index.php — this page sits
         beside it in the public nav and must line up with the same edges. -->

    <section class="about-section">
        <div class="container is-chrome">

            <!-- g-4, not g-5: at 360px --container-gutter is 16px and a g-5 row's
                 -24px margin punches past it, scrolling every phone sideways. -->
            <div class="row align-items-center g-4">

                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-image">
                        <img src="assets/images/home/oppam-proposal-01-800-700.webp" alt=""
                             class="img-fluid" width="800" height="700"
                             fetchpriority="high" decoding="async">
                    </div>
                </div>

                <div class="col-lg-6 col-md-6 col-sm-12 col-12">
                    <div class="about-content">
                        <p class="eyebrow">About Oppam</p>
                        <h1>Marriages that begin with trust</h1>
                        <p>Oppam Matrimony was built for Malayali families who wanted the reach of
                            an online search without giving up the things that make a Kerala
                            marriage work — verified people, families involved early, and nothing
                            about you shared without your say-so.</p>
                        <p>We are not the biggest matrimony site. We are the one where every
                            profile has been checked by a person before anyone can write to it.</p>

                        <div class="about-stats">
                            <div class="stat-tile">
                                <div class="stat-icon">
                                    <img src="assets/images/home/stat-downloads.png" class="img-fluid" alt=""
                                         width="128" height="128" loading="lazy" decoding="async">
                                </div>
                                <h2><span class="counter" data-target="105">0</span> K<sup>+</sup></h2>
                                <p class="text-muted-brand">Downloaded App</p>
                            </div>
                            <div class="stat-tile">
                                <div class="stat-icon">
                                    <img src="assets/images/about/heart.png" class="img-fluid" alt=""
                                         width="128" height="128" loading="lazy" decoding="async">
                                </div>
                                <h2><span class="counter" data-target="90">0</span> %</h2>
                                <p class="text-muted-brand">Successful Marriages</p>
                            </div>
                            <div class="stat-tile">
                                <div class="stat-icon">
                                    <img src="assets/images/about/computing.png" class="img-fluid" alt=""
                                         width="128" height="128" loading="lazy" decoding="async">
                                </div>
                                <h2><span class="counter" data-target="50">0</span> K<sup>+</sup></h2>
                                <p class="text-muted-brand">Verified Members</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- /*================ WHAT WE STAND FOR ================*/ -->

    <section class="about-values">
        <div class="container is-chrome">

            <div class="section-header">
                <p class="eyebrow">What we stand for</p>
                <h2>Four things we will not compromise on</h2>
                <div class="title-divider">
                    <span class="f-line"></span>
                    <span class="icon"><i class="fa fa-heart" aria-hidden="true"></i></span>
                    <span class="line"></span>
                </div>
            </div>

            <div class="row g-4">
                <?php foreach ($values as $v): ?>
                    <div class="col-lg-6 col-md-6 col-12">
                        <div class="value-card">
                            <span class="value-icon">
                                <i class="fa <?php ee($v['icon']); ?>" aria-hidden="true"></i>
                            </span>
                            <div>
                                <h3><?php ee($v['title']); ?></h3>
                                <p><?php ee($v['text']); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

    <!-- /*================ THE STORY ================*/ -->

    <section class="about-story">
        <div class="container is-readable">

            <h2 class="about-story-head">How the site came about</h2>

            <p class="p-main">Oppam started as a register kept by a family in Thrissur who had spent
                two decades arranging marriages in their own community — on paper, by reputation, and
                by knowing everybody's relatives. It worked, and it did not scale past one district.</p>

            <p class="p-main">The site is that register, opened up. What we kept was the part that
                mattered: somebody checks who you are before your profile goes live, and nobody gets
                your phone number because they paid for it. What we added was reach — a member in
                Kannur can now be matched with a family in Kollam without either of them knowing the
                same broker.</p>

            <p class="p-main">The names on the <a href="<?php ee(url('success-stories.php')); ?>">success
                stories page</a> are real couples who agreed to let us tell you how it went.</p>

        </div>
    </section>

    <!-- /*================ CTA ================*/
         Members already have an account, so the CTA branches on is_logged_in() —
         the same seam the header and footer use. -->

    <section class="stories-cta">
        <div class="container is-chrome">
            <div class="stories-cta-inner">
                <?php if (is_logged_in()): ?>
                    <h2>Your profile is your introduction.</h2>
                    <p>A complete profile gets shown to more families across Kerala.</p>
                    <a href="<?php ee(url('my-profile.php')); ?>" class="view-btn">
                        My Profile <i class="fa fa-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php else: ?>
                    <h2>Start with a free profile.</h2>
                    <p>It takes a few minutes, and nothing is visible until you say so.</p>
                    <a href="<?php ee(url('register.php')); ?>" class="view-btn">
                        Register Free <i class="fa fa-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    </main>

    <?php include_once('assets/includes/footer.php') ?>
    <?php include_once('assets/includes/footer2.php') ?>
    <?php include_once('assets/includes/script.php') ?>
</body>

</html>
