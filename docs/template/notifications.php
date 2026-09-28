<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* =============================================================================
   TODO(backend): demo data. Nothing here is wired.

   The count in the header bells (header.php: .nav-bell / .mobile-bell) is a
   hardcoded 3 in BOTH places. When this page becomes real, drive that count from
   the session and keep the two bells in step — they are the same number on two
   surfaces and they have drifted before.

   'type' selects the icon and the accent; keep the list in step with $filters
   below. 'href' is where the notification takes you — every one of them must
   lead somewhere real, which is why none of them is '#'.
   ============================================================================= */
$notifications = [
    ['type' => 'interest', 'unread' => true,  'time' => '10 minutes ago',
     'text' => 'Anna Thomas sent you an interest.',
     'href' => 'interest.php'],
    ['type' => 'message',  'unread' => true,  'time' => '1 hour ago',
     'text' => 'You have 2 unread messages from Anna Thomas.',
     'href' => 'messages.php'],
    ['type' => 'view',     'unread' => true,  'time' => '3 hours ago',
     'text' => 'Meera Nair viewed your profile.',
     'href' => 'my-matches.php'],
    ['type' => 'match',    'unread' => false, 'time' => 'Today, 07:00',
     'text' => "Your daily matches are ready — 8 new profiles.",
     'href' => 'daily-matches.php'],
    ['type' => 'interest', 'unread' => false, 'time' => 'Yesterday',
     'text' => 'Aparna Menon accepted your interest.',
     'href' => 'interest.php'],
    ['type' => 'system',   'unread' => false, 'time' => 'Yesterday',
     'text' => 'Your profile is 80% complete. Add your photos to finish it.',
     'href' => 'profile-photos.php'],
    ['type' => 'view',     'unread' => false, 'time' => '2 days ago',
     'text' => 'Divya Krishnan and 4 others viewed your profile.',
     'href' => 'my-matches.php'],
    ['type' => 'system',   'unread' => false, 'time' => '3 days ago',
     'text' => 'Verify your identity to get the verified badge on your profile.',
     'href' => 'verification.php'],
];

/* The left rail. TODO(backend): inert — none of these filter anything yet. The
   hrefs carry ?type= already, so the real page needs only a $_GET read here. */
$filters = [
    ['label' => 'All',        'type' => '',          'icon' => 'fa-bell-o'],
    ['label' => 'Interests',  'type' => 'interest',  'icon' => 'fa-heart-o'],
    ['label' => 'Messages',   'type' => 'message',   'icon' => 'fa-envelope-o'],
    ['label' => 'Profile views', 'type' => 'view',   'icon' => 'fa-eye'],
    ['label' => 'Matches',    'type' => 'match',     'icon' => 'fa-bolt'],
    ['label' => 'Account',    'type' => 'system',    'icon' => 'fa-cog'],
];

/* icon + accent class per type — one map, so the list and the rail agree. */
$type_icons = [
    'interest' => 'fa-heart',
    'message'  => 'fa-envelope',
    'view'     => 'fa-eye',
    'match'    => 'fa-bolt',
    'system'   => 'fa-cog',
];

$unread_total = count(array_filter($notifications, function ($n) { return $n['unread']; }));

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Notifications | Oppam Matrimony';
$page_desc     = 'Interests, profile views, messages and account updates from your Oppam Matrimony account.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Matrimony Notifications';
$og_title      = 'Your Notifications';
$og_desc       = 'Everything that happened on your Oppam Matrimony profile.';
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

    <!-- =========================
        NOTIFICATIONS — the standard 3 / 6 / 3:
        type filters (col-3) | the list (col-6) | ads (col-3)
    ========================= -->

    <section class="dashboard-section notifications-section">

        <div class="container">

            <div class="row dashboard-row">

                <!-- =========================
                    LEFT RAIL — filter by type
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <nav class="side-bar" aria-label="Notification types" data-rail="menu">

                        <p class="sidebar-section-title">Filter by</p>

                        <?php foreach ($filters as $i => $f): ?>
                            <?php
                            /* TODO(backend): 'All' is hardcoded as the current filter.
                               Read $_GET['type'] and compare against $f['type']. */
                            $f_current = ($i === 0);
                            $f_href    = 'notifications.php' . ($f['type'] !== '' ? '?type=' . $f['type'] : '');
                            ?>
                            <a href="<?php ee(url($f_href)); ?>"
                               class="nav-link-custom<?php ee($f_current ? ' active' : ''); ?>"
                               <?php ee($f_current ? 'aria-current="page"' : ''); ?>>

                                <span class="nav-link-label">
                                    <i class="fa <?php ee($f['icon']); ?>" aria-hidden="true"></i>
                                    <?php ee($f['label']); ?>
                                </span>

                                <span class="nav-count">
                                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                                </span>

                            </a>
                        <?php endforeach; ?>

                    </nav>

                </div>

                <!-- =========================
                    CENTRE — the list
                ========================= -->

                <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-content">

                        <div class="content-header nt-header">

                            <div>
                                <h1>Notifications</h1>
                                <p><?php ee($unread_total); ?> unread</p>
                            </div>

                            <!-- TODO(backend): no handler — POST this and clear the unread
                                 flags, then redirect back. A <button> and not <a href="#">:
                                 it performs an action, it does not go anywhere. -->
                            <button type="button" class="nt-mark-all">
                                Mark all as read
                            </button>

                        </div>

                        <ul class="nt-list">

                            <?php foreach ($notifications as $n): ?>
                                <li class="nt-item<?php ee($n['unread'] ? ' is-unread' : ''); ?>">

                                    <span class="nt-icon nt-icon-<?php ee($n['type']); ?>">
                                        <i class="fa <?php ee($type_icons[$n['type']] ?? 'fa-bell'); ?>" aria-hidden="true"></i>
                                    </span>

                                    <span class="nt-body">
                                        <!-- .stretched-link makes the whole row clickable without
                                             wrapping the row in an anchor. -->
                                        <a href="<?php ee(url($n['href'])); ?>" class="nt-text stretched-link">
                                            <?php ee($n['text']); ?>
                                        </a>
                                        <span class="nt-time text-muted-brand"><?php ee($n['time']); ?></span>
                                    </span>

                                    <?php if ($n['unread']): ?>
                                        <span class="nt-dot" aria-hidden="true"></span>
                                        <span class="visually-hidden">Unread</span>
                                    <?php endif; ?>

                                </li>
                            <?php endforeach; ?>

                        </ul>

                    </div>

                </div>

                <!-- =========================
                    RIGHT RAIL — shared promos
                ========================= -->

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
