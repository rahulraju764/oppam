<?php
/* =============================================================================
   404 — PAGE NOT FOUND
   =============================================================================
   Reached two ways, and BOTH matter:

     1. .htaccess rewrite rule 4 — any URL under the install directory that is
        not a real file, not a real directory and has no matching .php behind it
        is served this page. That is the common case, because the site serves
        extensionless URLs: a typo like /serach never touches a real file.
     2. ErrorDocument, if a future host sets one. Nothing here depends on which
        route was taken.

   THE STATUS CODE IS THE WHOLE POINT.
   An internal RewriteRule does not change the response status — Apache would
   serve this page with 200 OK. A "soft 404" is worse than Apache's ugly default
   page: crawlers index the error, and a broken internal link never shows up in
   any report because every URL on the site returns success. http_response_code()
   below is what makes it a real 404, so don't remove it.

   This page is PUBLIC in the sense that a logged-out visitor can land on it, but
   it is deliberately NOT flagged $is_public: the flag means "render the logged-out
   chrome", and a member who mistypes a URL should keep their own navigation. So
   the header/footer branch on the real is_logged_in() like every other page, and
   the CTA block below branches too — a member is offered Browse, a visitor is
   offered Register.

   TODO(backend): once is_logged_in() reads $_SESSION this file needs no change.
   ============================================================================= */
require_once __DIR__ . '/assets/includes/auth.php';

http_response_code(404);

/* HEAD — canonical, og:*, robots gating and the CSS/JS links all come from
   assets/includes/head.php.

   noindex is not optional here and is not the SITE_LIVE demo gate: an error page
   must never be indexed even after launch. A 404 status alone usually keeps it
   out, but the page is also reachable at its own URL (/404), which returns this
   same 404 — belt and braces. */
$page_title    = 'Page Not Found | Oppam Matrimony';
$page_desc     = 'The page you were looking for does not exist or has moved.';
$page_robots   = 'noindex, nofollow';

?>
<!doctype html>
<html lang="en">

<head>
<?php include __DIR__ . '/assets/includes/head.php'; ?>
</head>

<body>

    <?php include_once('assets/includes/header.php') ?>

    <main id="main" tabindex="-1">

    <!-- /*================ 404 ================*/
         is-readable (1200px), not is-chrome: this is a short block of prose and
         a link list, and it reads as a mistake-recovery page only if it stays
         narrow. Full-bleed at 1920 would fling six links across the screen. -->

    <section class="error-section">
        <div class="container is-readable">

            <div class="error-inner">

                <!-- Decorative, and marked so: the <h1> immediately below says
                     the same thing in words, and a screen reader announcing
                     "four zero four" before it is noise. -->
                <p class="error-code" aria-hidden="true">404</p>

                <h1 class="error-title">We couldn&rsquo;t find that page</h1>

                <p class="error-lead">The link may be out of date, or the address
                    may have a typo in it. Nothing is wrong with your account.</p>

                <!-- The primary way out. home_url() already branches: a member
                     goes to the dashboard, a visitor to the landing page — so
                     this one button is right for both without a condition. -->
                <a href="<?php ee(home_url()); ?>" class="view-btn error-btn">
                    <i class="fa fa-home" aria-hidden="true"></i> Back to home
                </a>

                <!-- Secondary routes. Deliberately short: the header and footer
                     already carry the full navigation, so this is a shortlist of
                     the places people actually mistype their way out of, not a
                     second sitemap. Member and visitor want different ones. -->
                <div class="error-links">
                    <h2 class="error-links-head">Or try one of these</h2>

                    <?php if (is_logged_in()): ?>
                        <ul class="error-link-list">
                            <li><a href="<?php ee(url('all-profiles.php')); ?>">Browse all profiles</a></li>
                            <li><a href="<?php ee(url('my-matches.php')); ?>">My matches</a></li>
                            <li><a href="<?php ee(url('search.php')); ?>">Search profiles</a></li>
                            <li><a href="<?php ee(url('my-profile.php')); ?>">My profile</a></li>
                            <li><a href="<?php ee(url('package.php')); ?>">Membership plans</a></li>
                            <li><a href="<?php ee(url('contact.php')); ?>">Contact us</a></li>
                        </ul>
                    <?php else: ?>
                        <ul class="error-link-list">
                            <li><a href="<?php ee(url('register.php')); ?>">Register free</a></li>
                            <li><a href="<?php ee(url('login.php')); ?>">Login</a></li>
                            <li><a href="<?php ee(url('about.php')); ?>">About us</a></li>
                            <li><a href="<?php ee(url('package.php')); ?>">Membership plans</a></li>
                            <li><a href="<?php ee(url('success-stories.php')); ?>">Success stories</a></li>
                            <li><a href="<?php ee(url('contact.php')); ?>">Contact us</a></li>
                        </ul>
                    <?php endif; ?>
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
