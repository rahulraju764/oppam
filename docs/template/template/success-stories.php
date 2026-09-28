<?php
// MEMBER page — but reachable from the PUBLIC footer too, which is why it is not
// flagged $is_public and why it is indexable: these are marketing pages that a
// logged-in member also links to (same treatment as package / contact / privacy).
// TODO(backend): once auth is real, this page stays UNGUARDED — it is public.
require_once __DIR__ . '/assets/includes/auth.php';
require_once __DIR__ . '/assets/includes/stories-data.php';

/* TODO(backend): demo couples. Replace stories_data() with the real query —
   see assets/includes/stories-data.php. This page and the .stories rail on
   single-profile.php both read it, so they can never drift apart again. */
$stories = stories_data();

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Wedding Success Stories | Oppam Matrimony';
$page_desc     = 'Real Kerala couples who met through Oppam Matrimony — how they matched, how their families met, and when they married.';
$page_keywords = 'Kerala Matrimony Success Stories, Oppam Matrimony Weddings, Malayali Wedding Stories, Kerala Brides, Kerala Grooms';
$og_title      = 'Real Couples, Real Weddings';
$og_desc       = 'Read how Kerala couples found each other on Oppam Matrimony.';
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

    <!-- /*================ SUCCESS STORIES ================*/ -->

    <section class="stories-section">
        <div class="container is-chrome">

            <div class="section-header">
                <p class="eyebrow">Success Stories</p>
                <h1 class="script-accent">Stories that started here</h1>
                <div class="title-divider">
                    <span class="f-line"></span>
                    <span class="icon"><i class="fa fa-heart" aria-hidden="true"></i></span>
                    <span class="line"></span>
                </div>
                <p class="stories-intro">Every couple below met on Oppam Matrimony. Their families
                    agreed to let us tell you how it happened.</p>
            </div>

            <!-- The grid. One loop over stories_data() — the same source the rail on
                 single-profile.php reads. g-4, not g-5: a -24px row margin punches
                 past --container-gutter at 360px and gives the page a horizontal
                 scrollbar (see CLAUDE.md). -->
            <div class="row g-4 story-grid">
                <?php foreach ($stories as $s): ?>
                    <?php
                    $story = $s;
                    include __DIR__ . '/assets/includes/story-card.php';
                    ?>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

    <!-- /*================ THE FULL WRITE-UPS ================*/
         Each card above links to its couple's anchor down here. That is why no
         per-couple page exists and why "Read their story" is not a dead link. -->

    <section class="story-longform">
        <div class="container is-readable">

            <h2 class="story-longform-head">In their words</h2>

            <?php foreach ($stories as $s): ?>
                <article class="story-full" id="story-<?php ee($s['slug']); ?>">

                    <div class="story-full-head">
                        <div class="story-full-img">
                            <img src="<?php ee($s['img']); ?>" class="img-fluid" alt=""
                                 width="<?php ee($s['w']); ?>" height="<?php ee($s['h']); ?>"
                                 loading="lazy" decoding="async">
                        </div>
                        <div class="story-full-title">
                            <h3><?php ee($s['couple']); ?></h3>
                            <p class="text-muted-brand"><?php ee($s['place']); ?> &middot; <?php ee($s['date']); ?></p>
                        </div>
                    </div>

                    <?php foreach ($s['story'] as $para): ?>
                        <p class="p-main"><?php ee($para); ?></p>
                    <?php endforeach; ?>

                </article>
            <?php endforeach; ?>

        </div>
    </section>

    <!-- /*================ CTA ================*/
         Members already have an account, so the CTA differs by who is looking —
         is_logged_in() is the same seam the header and footer branch on. -->

    <section class="stories-cta">
        <div class="container is-chrome">
            <div class="stories-cta-inner">
                <h2>Yours could be the next one.</h2>
                <?php if (is_logged_in()): ?>
                    <p>Keep your profile complete and your daily matches coming.</p>
                    <a href="<?php ee(url('all-profiles.php')); ?>" class="view-btn">
                        Browse Profiles <i class="fa fa-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php else: ?>
                    <p>Registration is free, and every profile is verified before it goes live.</p>
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
