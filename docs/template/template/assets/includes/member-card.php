<?php
/* =============================================================================
   MEMBER CARD  —  the 6-up `.member-card` grid tile
   =============================================================================
   Two copies: index.php's "Newly Joined Members" band and single-profile.php's
   "Similar Profiles" rail. Identical markup, identical column classes.

   USAGE
       <?php foreach ($members as $m): ?>
           <?php $profile = $m; include __DIR__ . '/assets/includes/member-card.php'; ?>
       <?php endforeach; ?>

   $profile keys: img, name, place, and optionally href.

   It emits its own Bootstrap column, because the two call sites disagreed about
   nothing except indentation and there is no reason for a caller to choose.

   The link is a full-tile overlay anchor (`.member-link`) carrying a visually-
   hidden label rather than a `.stretched-link` on the name: the name here is an
   <h5> inside an absolutely-positioned `.member-info`, and stretching from
   inside a positioned ancestor only covers that ancestor, not the photo.
   ============================================================================= */

$profile = $profile ?? [];
$c_href  = $profile['href'] ?? 'single-profile.php';
?>
<div class="col-lg-2 col-md-4 col-sm-6 col-6">
    <div class="member-card">
        <div class="member-image">
            <!-- alt="" — the name is the <h5> in the overlay below. -->
            <img src="<?php ee($profile['img']); ?>" alt="" class="img-fluid"
                 width="600" height="600" loading="lazy" decoding="async">
        </div>
        <div class="member-info">
            <div class="overlay"></div>
            <h5><?php ee($profile['name']); ?></h5>
            <p><?php ee($profile['place']); ?></p>
        </div>
        <a href="<?php ee(url($c_href)); ?>" class="member-link">
            <span class="visually-hidden">View <?php ee($profile['name']); ?>’s profile</span>
        </a>
    </div>
</div>
<?php unset($profile, $c_href);
