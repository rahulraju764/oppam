<?php
/* Shared pagination bar.
   TODO(backend): static — page numbers and the current page are hardcoded here.
   Wire $pg_current / $pg_total to the real query and give each link a real href.

   PLACEMENT: include it in the COLUMN, after the .dashboard-content card closes —
   never inside .home-content. The bar is chrome for the card, not a row in it, and
   it reads as one on every page only if it sits on the page background everywhere.

   Usage (set the two vars, then include):

       <?php $pg_label = 'Match results pages'; ?>
       <?php include('assets/includes/pagination.php'); ?>

   $pg_label   — aria-label for the <nav>, so a screen reader can tell two
                 pagers on one page apart. Required in spirit; falls back below.
   $pg_current — 1-based current page (default 1)
   $pg_total   — total pages (default 42, the demo number) */

$pg_label   = $pg_label   ?? 'Pagination';
$pg_current = $pg_current ?? 1;
$pg_total   = $pg_total   ?? 42;
?>

<nav class="pagination-bar" aria-label="<?php ee($pg_label); ?>">

    <a href="#" class="page-link is-disabled" aria-disabled="true" aria-label="Previous page">
        <i class="fa fa-angle-left" aria-hidden="true"></i>
    </a>

    <a href="#" class="page-link is-current" aria-current="page">1</a>
    <a href="#" class="page-link">2</a>
    <a href="#" class="page-link">3</a>
    <span class="page-gap" aria-hidden="true">…</span>
    <a href="#" class="page-link"><?php ee($pg_total); ?></a>

    <a href="#" class="page-link" aria-label="Next page">
        <i class="fa fa-angle-right" aria-hidden="true"></i>
    </a>

</nav>

<?php
/* Don't leak this page's settings into a second pager further down. */
unset($pg_label, $pg_current, $pg_total);
