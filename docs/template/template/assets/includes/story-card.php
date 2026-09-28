<?php
/* =============================================================================
   STORY CARD  —  one success-story couple
   =============================================================================
   Two surfaces, one file — the same reason profile-row.php exists. It replaces
   the three byte-identical "Allen & Riya" slides that were hand-copied into
   single-profile.php's right rail, all three pointing at href="#".

   USAGE
       <?php foreach (stories_data() as $s): ?>
           <?php $story = $s; include __DIR__ . '/assets/includes/story-card.php'; ?>
       <?php endforeach; ?>

   $story keys — see stories-data.php: slug, couple, place, date, img, w, h, quote

   $story_variant selects the shell:
       'grid'  (default)  a .story-card tile in the success-stories.php grid
       'slide'            a .parent-stories .swiper-slide for the rail carousel

   Both are reset at the end, so a page rendering a grid AND a rail cannot leak
   the first one's variant into the second.

   The link always resolves — it targets the couple's full write-up on
   success-stories.php, which is a real anchor on a real page.
   ============================================================================= */

$story         = $story         ?? [];
$story_variant = $story_variant ?? 'grid';

$t_href = 'success-stories.php#story-' . ($story['slug'] ?? '');
?>

<?php if ($story_variant === 'slide'): ?>

    <div class="parent-stories swiper-slide">
        <div class="stories-image">
            <img src="<?php ee($story['img']); ?>" class="img-fluid" alt=""
                 width="<?php ee($story['w']); ?>" height="<?php ee($story['h']); ?>"
                 loading="lazy" decoding="async">
        </div>
        <div class="stories-content">
            <h2><?php ee($story['couple']); ?></h2>
        </div>
        <div class="readstory-btn">
            <a href="<?php ee(url($t_href)); ?>">Read their story</a>
        </div>
    </div>

<?php else: ?>

    <div class="col-lg-4 col-md-6 col-12">
        <div class="story-card">

            <div class="story-card-img">
                <!-- alt="" is deliberate: the couple's name is the adjacent <h3>. -->
                <img src="<?php ee($story['img']); ?>" class="img-fluid" alt=""
                     width="<?php ee($story['w']); ?>" height="<?php ee($story['h']); ?>"
                     loading="lazy" decoding="async">
            </div>

            <div class="story-card-body">
                <h3 class="story-card-name">
                    <!-- stretched-link, not a wrapping <a> — the card must not become
                         an anchor containing anchors. -->
                    <a href="<?php ee(url($t_href)); ?>" class="stretched-link">
                        <?php ee($story['couple']); ?>
                    </a>
                </h3>
                <p class="story-card-meta text-muted-brand">
                    <?php ee($story['place']); ?> &middot; <?php ee($story['date']); ?>
                </p>
                <p class="story-card-quote">&ldquo;<?php ee($story['quote']); ?>&rdquo;</p>
            </div>

        </div>
    </div>

<?php endif; ?>
<?php
/* Reset, so the next include starts from the documented defaults. */
unset($story, $t_href);
$story_variant = 'grid';
