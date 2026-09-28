<!-- =========================
     ADS RAIL — the rightmost column on member pages (3 / 6 / 3).
     Include inside a col-lg-3:  <?php include_once('assets/includes/ads.php') ?>

     Sticky on desktop; put it LAST in the row so a phone gets the content
     before the promos.

     TODO(backend): all four units are hardcoded demo creative — swap for real
     campaigns / an ad slot when there is a backend.
========================= -->

<aside class="ad-rail" aria-label="Sponsored">

    <!-- 1. UPGRADE PROMO — brand accent, like .all-matches -->

    <div class="ad-unit ad-promo" data-rail="weave">

        <span class="ad-promo-icon">
            <i class="fa fa-diamond" aria-hidden="true"></i>
        </span>

        <h3>Go Premium</h3>

        <p>Call and chat with your matches, see who viewed you, and get 3x more responses.</p>

        <a href="package" class="ad-promo-btn">Upgrade Now</a>

    </div>


    <!-- 2. OFFER BANNER -->

    <a href="package" class="ad-unit ad-banner" data-rail="weave">
        <span class="ad-label">Sponsored</span>
        <img src="assets/images/home/ad1.jpg" class="img-fluid" alt="Limited time membership offer" width="800" height="800" loading="lazy" decoding="async">
    </a>


    <!-- 3. SPONSORED CAROUSEL -->

    <div class="ad-unit ad-carousel" data-rail="weave">

        <span class="ad-label">Sponsored</span>

        <div class="ad-oppam swiper">

            <div class="swiper-wrapper">

                <div class="oppam-ad swiper-slide">
                    <div class="oppam-img">
                        <img src="assets/images/search/ed-ad1.jpeg" alt="" width="810" height="1013" loading="lazy" decoding="async">
                    </div>
                </div>

                <div class="oppam-ad swiper-slide">
                    <div class="oppam-img">
                        <img src="assets/images/search/ed-ad2.jpeg" alt="" width="810" height="1013" loading="lazy" decoding="async">
                    </div>
                </div>

            </div>

            <div class="swiper-pagination"></div>

        </div>

    </div>


    <!-- 4. WEDDING SERVICES -->

    <div class="ad-unit ad-services" data-rail="weave">

        <span class="ad-label">Sponsored</span>

        <h3>Wedding Services</h3>

        <ul class="ad-service-list">

            <li>
                <a href="#">
                    <i class="fa fa-camera" aria-hidden="true"></i>
                    Photographers
                </a>
            </li>

            <li>
                <a href="#">
                    <i class="fa fa-cutlery" aria-hidden="true"></i>
                    Catering
                </a>
            </li>

            <li>
                <a href="#">
                    <i class="fa fa-home" aria-hidden="true"></i>
                    Wedding Halls
                </a>
            </li>

            <li>
                <a href="#">
                    <i class="fa fa-magic" aria-hidden="true"></i>
                    Bridal Makeup
                </a>
            </li>

        </ul>

    </div>

</aside>
