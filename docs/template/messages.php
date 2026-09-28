<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* =============================================================================
   TODO(backend): demo data. Nothing here is wired.

   This page is the LAYOUT for messaging, not messaging. Every control is inert
   and says so — the composer POSTs nowhere, the thread list is a static array,
   and the unread counts are literals. Build the real thing against this shell
   rather than inventing a second one; the whole point of the skeleton is that
   the markup and the CSS are already agreed.

   WHAT THE REAL PAGE NEEDS
     - $_GET['thread'] selects the open conversation (the list links carry it
       already, so the links do not change).
     - The composer POSTs to this page; on success, redirect back to
       ?thread=<id> so a refresh does not resend (POST/redirect/GET).
     - Unread counts and 'time' come from the query.
     - Messaging is a PAID feature on this site — see the membership box in the
       header dropdown. Gate the composer, not the thread list: a free member
       should see that someone wrote to them.
   ============================================================================= */
$threads = [
    [
        'id'     => 't1',
        'name'   => 'Anna Thomas',
        'mid'    => 'VIS12370',
        'img'    => 'assets/images/matches/profile.webp',
        'last'   => 'That works for us. Shall we say Sunday evening?',
        'time'   => '10:24',
        'unread' => 2,
    ],
    [
        'id'     => 't2',
        'name'   => 'Meera Nair',
        'mid'    => 'VIS12384',
        'img'    => 'assets/images/matches/600-600-1.webp',
        'last'   => 'My father would like to speak to yours first.',
        'time'   => 'Yesterday',
        'unread' => 0,
    ],
    [
        'id'     => 't3',
        'name'   => 'Aparna Menon',
        'mid'    => 'VIS12391',
        'img'    => 'assets/images/home/profile1.webp',
        'last'   => 'Thank you for the interest — reading your profile now.',
        'time'   => 'Yesterday',
        'unread' => 1,
    ],
    [
        'id'     => 't4',
        'name'   => 'Divya Krishnan',
        'mid'    => 'VIS12403',
        'img'    => 'assets/images/home/profile2.webp',
        'last'   => 'We are in Ernakulam too, so that is easy.',
        'time'   => 'Mon',
        'unread' => 0,
    ],
    [
        'id'     => 't5',
        'name'   => 'Sneha Pillai',
        'mid'    => 'VIS12418',
        'img'    => 'assets/images/home/profile3.webp',
        'last'   => 'I work nights this week, so replies may be slow.',
        'time'   => 'Sun',
        'unread' => 0,
    ],
];

/* The open conversation. Hardcoded to the first thread — TODO(backend): read
   $_GET['thread'] and fall back to the most recent. 'me' => true is the member
   writing; false is the match. */
$open_thread = $threads[0];

$open_messages = [
    ['me' => false, 'time' => 'Sat 19:02', 'text' => 'Thank you for the interest. My sister set up my profile, so forgive anything odd in it.'],
    ['me' => true,  'time' => 'Sat 19:40', 'text' => 'Nothing odd at all. Mine was written by my mother, which is worse.'],
    ['me' => false, 'time' => 'Sat 19:44', 'text' => 'You are in Thrissur? We are near the temple, about ten minutes from the centre.'],
    ['me' => true,  'time' => 'Sun 08:15', 'text' => 'Yes, and my parents are still there. They would like to meet yours when it suits.'],
    ['me' => false, 'time' => '10:24',     'text' => 'That works for us. Shall we say Sunday evening?'],
];

$unread_total = array_sum(array_column($threads, 'unread'));

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Messages | Oppam Matrimony';
$page_desc     = 'Read and reply to messages from your Kerala matrimony matches.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Matrimony Messages, Malayali Matrimony';
$og_title      = 'Your Conversations';
$og_desc       = 'Message your matches directly on Oppam Matrimony.';
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
        MESSAGES — the same 3 / 6 / 3 split as dashboard.php:
        conversations (col-3) | open thread (col-6) | ads (col-3)

        A two-pane inbox is the obvious shape for this page, and it happens to BE
        the 3/6/3: the left rail is this page's own content (every member page's
        rail is), so nothing new had to be invented. Below 992px the columns stack
        list-then-thread, which is a demo compromise — TODO(backend): on mobile the
        real page should show ONE of the two at a time, list until a thread is
        chosen. Do not solve that by making this a 3/9 page.
    ========================= -->

    <section class="dashboard-section messages-section">

        <div class="container">

            <h1 class="visually-hidden">Messages</h1>

            <div class="row dashboard-row">

                <!-- =========================
                    LEFT RAIL — the conversation list
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <nav class="side-bar msg-list" aria-label="Conversations">

                        <div class="msg-list-head">
                            <h2>Conversations</h2>
                            <?php if ($unread_total > 0): ?>
                                <span class="chip"><?php ee($unread_total); ?> unread</span>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($threads as $t): ?>
                            <?php $is_open = ($t['id'] === $open_thread['id']); ?>

                            <!-- The href already carries ?thread= so the real page needs no
                                 markup change — only a $_GET read at the top of this file. -->
                            <a class="msg-thread<?php ee($is_open ? ' is-current' : ''); ?>"
                               href="<?php ee(url('messages.php?thread=' . $t['id'])); ?>"
                               <?php ee($is_open ? 'aria-current="page"' : ''); ?>>

                                <span class="msg-thread-img">
                                    <img src="<?php ee($t['img']); ?>" alt=""
                                         width="600" height="600" loading="lazy" decoding="async">
                                </span>

                                <span class="msg-thread-body">
                                    <span class="msg-thread-top">
                                        <strong class="msg-thread-name"><?php ee($t['name']); ?></strong>
                                        <span class="msg-thread-time"><?php ee($t['time']); ?></span>
                                    </span>
                                    <span class="msg-thread-last"><?php ee($t['last']); ?></span>
                                </span>

                                <?php if ($t['unread'] > 0): ?>
                                    <!-- The visible number is aria-hidden and the count is
                                         spelled out for AT instead — "2" alone next to a name
                                         announces as part of the name. -->
                                    <span class="msg-thread-badge" aria-hidden="true"><?php ee($t['unread']); ?></span>
                                    <span class="visually-hidden"><?php ee($t['unread']); ?> unread</span>
                                <?php endif; ?>

                            </a>
                        <?php endforeach; ?>

                    </nav>

                </div>

                <!-- =========================
                    CENTRE — the open thread
                ========================= -->

                <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-content">

                        <div class="msg-panel">

                            <!-- THREAD HEADER — the other member, and the way back to
                                 their profile. single-profile.php is the ONLY profile
                                 view; do not add a second one. -->
                            <div class="msg-panel-head">

                                <div class="msg-panel-who">
                                    <img src="<?php ee($open_thread['img']); ?>" alt=""
                                         width="600" height="600" loading="lazy" decoding="async">
                                    <div>
                                        <h2><?php ee($open_thread['name']); ?></h2>
                                        <p class="text-muted-brand"><?php ee($open_thread['mid']); ?></p>
                                    </div>
                                </div>

                                <a href="<?php ee(url('single-profile.php')); ?>" class="msg-panel-view">
                                    View profile
                                </a>

                            </div>

                            <!-- THE THREAD.
                                 role="log" so a screen reader announces messages that
                                 arrive later without the member having to go looking. -->
                            <div class="msg-thread-body-scroll" role="log" aria-label="Conversation with <?php ee($open_thread['name']); ?>">

                                <?php foreach ($open_messages as $m): ?>
                                    <div class="msg-bubble<?php ee($m['me'] ? ' is-me' : ''); ?>">
                                        <p class="msg-bubble-text"><?php ee($m['text']); ?></p>
                                        <p class="msg-bubble-time">
                                            <span class="visually-hidden"><?php ee($m['me'] ? 'You, ' : e($open_thread['name']) . ', '); ?></span>
                                            <?php ee($m['time']); ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>

                            </div>

                            <!-- COMPOSER.
                                 TODO(backend): no handler. It POSTs to this page and does
                                 nothing. The submit is a real <button type="submit"> —
                                 unlike the login/register CTAs, there is no demo navigation
                                 to fake here, so the honest control is the real one. -->
                            <form class="msg-composer" method="post" action="<?php ee(url('messages.php')); ?>">
                                <?php csrf_field(); ?>

                                <input type="hidden" name="thread" value="<?php ee($open_thread['id']); ?>">

                                <label for="msgText" class="visually-hidden">
                                    Message to <?php ee($open_thread['name']); ?>
                                </label>

                                <textarea class="form-control msg-composer-input" id="msgText" name="body"
                                          rows="2" placeholder="Write a message…"></textarea>

                                <button type="submit" class="btn msg-composer-send">
                                    <i class="fa fa-paper-plane" aria-hidden="true"></i>
                                    <span class="msg-composer-send-label">Send</span>
                                </button>

                            </form>

                        </div>

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
