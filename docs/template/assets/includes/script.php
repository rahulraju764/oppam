<!-- ============================ JS ============================
     Include this LAST, after both footers — custom.js queries the DOM as soon
     as it runs and does not wait for a load event to find elements.

     Five tags used to sit here that no longer do:

       jquery-3.7.1.min.js           85KB. Its last remaining use was the
                                     `$(function(){…})` ready wrapper in
                                     custom.js; see onReady() there.
       owl.carousel.min.js           replaced by Swiper, which was already loaded
                                     alongside it for the dashboard rails.
       wow.min.js                    never initialised, no .wow element exists.
       aos.js                        initialised, but no data-aos attribute
                                     exists on any page.
       jquery.magnific-popup.min.js  never initialised, no lightbox on the site.

     Before adding a library here, check something actually uses it.
     TODO(backend): Font Awesome is still 4.7 (2016, unmaintained). Upgrading to
     v6 renames every icon class site-wide (`fa fa-heart` -> `fa-solid fa-heart`),
     so it is a deliberate separate job, not a drive-by. -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
<script src="assets/js/custom.js?v=<?php ee(@filemtime(__DIR__ . '/../js/custom.js')); ?>"></script>
