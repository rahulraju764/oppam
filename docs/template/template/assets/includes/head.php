<?php
/* =============================================================================
   SHARED <head>  —  the whole of it, for all 21 rendered pages
   =============================================================================
   This block used to be pasted into every page. Three things were wrong in all
   21 copies at once, which is exactly why it is one file now:

     • og:url was the literal string "PAGE_URL"
     • og:site_name was empty
     • 20 pages declared <meta name="robots" content="index, follow"> while
       links.php declared "noindex, nofollow" — two contradictory directives in
       the same head, on a build that must not be indexed at all

   HOW A PAGE USES IT
   Set what differs, then include. Everything is optional; the defaults below
   describe the site rather than any one page.

       <?php
       $page_title    = 'All Profiles | Oppam Matrimony';
       $page_desc     = 'Discover compatible Kerala matrimony matches…';
       $page_keywords = 'Kerala Matrimony Matches, …';
       $og_title      = 'Discover Your Perfect Kerala Matrimony Match Today';
       $og_desc       = 'Explore personalized Kerala matrimony matches…';
       $page_robots   = 'noindex, nofollow';   // member pages only
       ?>
       <head><?php include __DIR__ . '/assets/includes/head.php'; ?></head>

   It ends by including links.php, so a page includes head.php and nothing else.
   ============================================================================= */

/* Defaults. A page that sets nothing still gets a complete, correct head. */
$page_title    = $page_title    ?? 'Oppam Matrimony | Trusted Kerala Matrimony';
$page_desc     = $page_desc     ?? 'Oppam Matrimony helps Malayalis in Kerala find genuine life partners through verified profiles, secure matchmaking and trusted connections.';
$page_keywords = $page_keywords ?? 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';

/* Open Graph falls back to the page's own title/description rather than to a
   generic site blurb — a share card repeating the same sentence on all 21 pages
   is worse than no card. */
$og_title  = $og_title  ?? $page_title;
$og_desc   = $og_desc   ?? $page_desc;

/* og:image must be ABSOLUTE — relative paths are dropped by every scraper.
   banner2.webp is 1920x700 and 174KB; banner1 is the same size at 1061KB, which
   is a poor thing to make Facebook fetch on every unfurl.
   TODO(backend): commission a purpose-built 1200x630 share card. 1920x700 is
   2.74:1 and gets centre-cropped to 1.91:1 by most platforms. */
$og_image     = $og_image     ?? 'assets/images/banner/banner2.webp';
$og_image_w   = $og_image_w   ?? 1920;
$og_image_h   = $og_image_h   ?? 700;

/* Per-page default: index. Member pages (a dashboard, someone's own profile, a
   match funnel) pass 'noindex, nofollow' — they are behind auth, so a crawler
   can only ever reach a login redirect, and indexing them leaks member names.
   While SITE_LIVE is false this is overridden site-wide anyway; see below. */
$page_robots = $page_robots ?? 'index, follow';

$canonical = canonical_url();
?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?php ee($page_title); ?></title>
    <meta name="description" content="<?php ee($page_desc); ?>">
    <meta name="keywords" content="<?php ee($page_keywords); ?>">
    <link rel="canonical" href="<?php ee($canonical); ?>">

<?php if (SITE_LIVE): ?>
    <meta name="robots" content="<?php ee($page_robots); ?>">
<?php else: ?>
    <!-- STAGING BUILD. SITE_LIVE is false in auth.php, so the page's own
         robots value (<?php ee($page_robots); ?>) is overridden here and every page is held out
         of every index. Flip SITE_LIVE to true at launch. -->
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="googlebot" content="noindex, nofollow">
<?php endif; ?>

    <!-- Open Graph -->
    <meta property="og:locale" content="en_IN">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Oppam Matrimony">
    <meta property="og:title" content="<?php ee($og_title); ?>">
    <meta property="og:description" content="<?php ee($og_desc); ?>">
    <meta property="og:url" content="<?php ee($canonical); ?>">
    <meta property="og:image" content="<?php ee(site_url($og_image)); ?>">
    <meta property="og:image:width" content="<?php ee($og_image_w); ?>">
    <meta property="og:image:height" content="<?php ee($og_image_h); ?>">

    <!-- Twitter reads most og:* tags but needs its own card type to render large. -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php ee($og_title); ?>">
    <meta name="twitter:description" content="<?php ee($og_desc); ?>">
    <meta name="twitter:image" content="<?php ee(site_url($og_image)); ?>">

    <meta name="theme-color" content="#e02349">

<?php include __DIR__ . '/links.php'; ?>
