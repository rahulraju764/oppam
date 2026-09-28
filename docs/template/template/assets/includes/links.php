<?php
/* Cache-buster: the browser re-fetches the stylesheet whenever it changes on disk,
   so an edit is never masked by a stale cached copy. */
$css_v = @filemtime(__DIR__ . '/../css/style.css') ?: '1';
$rsp_v = @filemtime(__DIR__ . '/../css/responsive.css') ?: '1';
?>
<!-- The robots directives used to live here AND in each page's own head, saying
     opposite things. They now live in head.php alone, gated on SITE_LIVE. -->

<!-- FONTS — Manrope (body/UI), Plus Jakarta Sans (headings), Great Vibes (script accent).
     These three only. One request; weights limited to those the type scale uses
     (--fw-regular/medium/semibold/bold = 400/500/600/700). Great Vibes ships 400 only. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Great+Vibes&display=swap" rel="stylesheet">

<!-- ============================ CSS ============================
     ORDER IS LOAD-BEARING: every vendor stylesheet first, then ours.

     It used to be interleaved — style.css sat above swiper-bundle.min.css and
     font-awesome — so our own rules LOST to the vendor's at equal specificity
     and had to be won back with extra qualifiers and !important. Adding a class
     to a selector purely to outrank a CDN file is a workaround for load order,
     not a design decision. Put ours last and specificity means what it says.

     Four stylesheets are gone from this list, and their files with them:

       owl.carousel.min.css }  Swiper does the same job and the site shipped both.
       owl.theme.default.css}  See the CAROUSELS block in custom.js.
       aos.css                 no element on the site has a data-aos attribute.
       animate.css             no element uses an .animate__* class.
       magnific-popup.css      no lightbox exists; the plugin was never initialised.

     assets/css/bootstrap.min.css is gone too — it was a second, unreferenced copy
     of the CDN file above. -->

<!-- vendor -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.css">

<!-- ours — last, so it wins -->
<link href="assets/css/style.css?v=<?php ee($css_v); ?>" rel="stylesheet">
<link href="assets/css/responsive.css?v=<?php ee($rsp_v); ?>" rel="stylesheet">

<link rel="icon" type="image/png" sizes="32x32" href="assets/images/logo/oppam-logo.webp">
