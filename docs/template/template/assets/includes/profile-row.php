<?php
/* =============================================================================
   PROFILE ROW  —  the `.profiles` result row
   =============================================================================
   The most-repeated component on the site. It existed FIVE times, hand-copied:
   all-profiles.php, daily-matches.php, my-matches.php, interest.php, search.php.
   The copies had already drifted:

     • daily-matches.php used `.pf-dt-dm` for the meta line where the other four
       used `.pf-dt-m`. The two CSS rules were byte-identical, and responsive.css
       had to name both classes in six separate rules to keep them in step.
       This partial emits `.pf-dt-m` only; `.pf-dt-dm` is gone from the CSS.
     • search.php dropped the comma between qualification and occupation
       ("MBA Consultant" instead of "MBA, Consultant").
     • search.php hardcoded "Last seen an hour ago" for every row.
     • Two copies had `aria-hidden` on the action icons, three did not.

   USAGE
       <?php foreach ($matches as $m): ?>
           <?php $profile = $m; include __DIR__ . '/assets/includes/profile-row.php'; ?>
       <?php endforeach; ?>

   $profile keys
       name, id, img            required
       age, height, study, work, place    the three meta lines
       pid        int|string — the profile's PRIMARY KEY. Both links on the row
                           point at single-profile.php?id=<pid>. See below.
       new        bool   — draws the NEWLY JOINED corner flag
       seen       string — becomes "Last seen <seen>" in the meta tail
       meta       string — overrides that tail outright (interest.php passes
                           "3 days ago" rather than a last-seen time)
       href       string — overrides the link outright, for the rare row that
                           points somewhere other than a profile

   pid vs id — they are NOT the same thing and must not be conflated:

       'id'   the member's DISPLAYED reference code, "VIS12370". Shown to the
              member, quoted in support tickets, printed on the row.
       'pid'  the row's key in the database. Never rendered as text; it only
              ever appears in the URL.

   The two are separate on purpose. A display code is a business identifier —
   it gets reformatted, re-issued after a re-registration, or prefixed per
   branch — and every one of those changes would break the URLs if the URL had
   been built from it. Keep the routing key and the human label independent.

   Omit pid and the row falls back to a bare single-profile.php with no query
   string, which is what every call site did before this key existed. That is a
   deliberate soft fallback, not an assertion the link is right: a demo array
   without a pid still renders and still navigates.

   TODO(backend): single-profile.php must treat ?id= as untrusted input — cast
   it, look it up, and 404 on a miss. Do NOT let it select which profile to show
   without the viewer's own permission check: an incrementable id in a URL is
   how a members-only directory becomes a public one.

   $profile_actions selects the button row:
       'interest' (default)  Don't show / Send Interest
       'respond'             Decline / Accept while $profile['status'] is
                             'pending', otherwise a status chip. interest.php.
       'none'                no action row

   Both are reset at the end, so a page that renders two different lists cannot
   leak the first list's settings into the second.
   ============================================================================= */

$profile         = $profile         ?? [];
$profile_actions = $profile_actions ?? 'interest';

/* rawurlencode, even though today's pids are digits: the key is whatever the
   backend hands us, and an unencoded '&' or '#' in it would truncate the URL.
   url() rewrites 'single-profile.php?id=…' to 'single-profile?id=…' — its regex
   already allows a '?' to follow the extension. */
$p_href = $profile['href']
    ?? (isset($profile['pid'])
        ? 'single-profile.php?id=' . rawurlencode((string) $profile['pid'])
        : 'single-profile.php');
$p_meta = $profile['meta'] ?? (isset($profile['seen']) ? 'Last seen ' . $profile['seen'] : '');
?>
<div class="profiles">

    <div class="profile-img">

        <!-- alt="" is deliberate: the member's name is the adjacent <h2>, so a
             filled alt would make a screen reader announce it twice. -->
        <img src="<?php ee($profile['img']); ?>" class="img-fluid" alt=""
             width="600" height="600" loading="lazy" decoding="async">

        <button type="button" class="short-list" aria-label="Shortlist <?php ee($profile['name']); ?>">
            <span class="short-icon">
                <i class="fa fa-bookmark" aria-hidden="true"></i>
            </span>
        </button>

        <?php if (!empty($profile['new'])): ?>
            <div class="triangle">
                <div class="triagle-content">
                    <span>NEWLY<br>JOINED</span>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <div class="profile-tittle">

        <!-- stretched-link: the whole row is clickable without nesting one anchor
             inside another, which is what the old markup did. -->
        <h2 class="pf-tt">
            <a href="<?php ee(url($p_href)); ?>" class="stretched-link"><?php ee($profile['name']); ?></a>
        </h2>

        <h3 class="pf-dt-m">
            <?php ee($profile['id']); ?>
            <?php if ($p_meta !== ''): ?><span><?php ee($p_meta); ?></span><?php endif; ?>
        </h3>

        <div class="profile-list">

            <ul class="list">
                <li class="age"><?php ee($profile['age']); ?><span><?php ee($profile['height']); ?></span></li>
                <li class="study"><?php ee($profile['study']); ?>,<span> <?php ee($profile['work']); ?></span></li>
                <li class="place"><?php ee($profile['place']); ?></li>
            </ul>

            <?php if ($profile_actions !== 'none'): ?>
                <div class="intst-parent is-flush">
                    <div class="intst-button">

                        <?php if ($profile_actions === 'respond'): ?>

                            <?php if (($profile['status'] ?? '') === 'pending'): ?>
                                <!-- TODO(backend): no handler. These POST nothing yet. -->
                                <button type="button" class="btn-decline">
                                    <i class="fa fa-times" aria-hidden="true"></i>Decline
                                </button>
                                <button type="button" class="btn-accept">
                                    <i class="fa fa-check" aria-hidden="true"></i>Accept
                                </button>
                            <?php else: ?>
                                <span class="chip status-<?php ee($profile['status'] ?? ''); ?>">
                                    <?php ee($profile['status_label'] ?? ''); ?>
                                </span>
                            <?php endif; ?>

                        <?php else: ?>

                            <!-- TODO(backend): inert — no handler. Restore to
                                 <button type="submit"> inside a <form> when one exists. -->
                            <a href="#"><i class="fa fa-window-close" aria-hidden="true"></i>Don't show</a>
                            <a class="send-int" href="#"><i class="fa fa-heart" aria-hidden="true"></i>Send Interest</a>

                        <?php endif; ?>

                    </div>
                </div>
            <?php endif; ?>

        </div>

    </div>

    <div class="icon-parent">
        <div class="profile-icons-m">
            <a href="<?php ee(url($p_href)); ?>">View profile</a>
        </div>
    </div>

</div>
<?php
/* Reset, so the next include on the page starts from the documented defaults
   rather than inheriting whatever the last caller happened to set. */
unset($profile, $p_href, $p_meta);
$profile_actions = 'interest';
