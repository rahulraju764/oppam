<?php
/* =============================================================================
   PROFILE TILE  —  the `.profile-card` slide inside a `.match-slider`
   =============================================================================
   dashboard.php carried TEN hand-written copies of this: five slides, duplicated
   verbatim between "Daily Recommendations" and "All Matches" — the same five
   members, the same photos, in both rails.

   USAGE (inside a .swiper-wrapper)
       <?php foreach ($daily as $m): ?>
           <?php $profile = $m; include __DIR__ . '/assets/includes/profile-tile.php'; ?>
       <?php endforeach; ?>

   $profile keys: img, name, age, height, and optionally href.

   It emits its own `.swiper-slide` wrapper — the tile IS the slide everywhere it
   is used, and leaving that to the caller was how the two rails drifted apart.
   ============================================================================= */

$profile = $profile ?? [];
$t_href  = $profile['href'] ?? 'single-profile.php';
?>
<div class="swiper-slide">
    <a href="<?php ee(url($t_href)); ?>" class="profile-link">
        <div class="profile-card">
            <!-- alt="" — the name is in the <h4> directly below. -->
            <img src="<?php ee($profile['img']); ?>" alt=""
                 width="600" height="600" loading="lazy" decoding="async">
            <div class="card-details">
                <h4><?php ee($profile['name']); ?></h4>
                <p><?php ee($profile['age']); ?>, <?php ee($profile['height']); ?></p>
            </div>
        </div>
    </a>
</div>
<?php unset($profile, $t_href);
