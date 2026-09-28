<?php require_once __DIR__ . '/auth.php'; ?>

<!-- =========================
        FOOTER AREA
     Public visitors get marketing links (register / how it works); members get
     their own pages. Same shell either way — only the "Explore" list branches.
========================= -->

<footer class="footer-area footer-bg-two">

    <!-- Capped to match the header — see --container-chrome in style.css. -->
    <div class="container is-chrome">

        <div class="row gy-5">

            <!-- BRAND, ABOUT & SOCIAL -->
            <div class="col-lg-4 col-md-6">

                <div class="footer-widget footer-brand">

                    <a href="<?php ee(home_url()); ?>" class="footer-logo">
                        <!-- Lazy, unlike the two in header.php: this one is at the bottom
                             of every page and is never the LCP element. Same file, so on
                             most pages it is already in cache by the time it scrolls in. -->
                        <img src="assets/images/logo/oppam-logo.webp" alt="Oppam Matrimony" width="600" height="301" loading="lazy" decoding="async">
                    </a>

                    <p class="footer-desc">
                        We help individuals and families find meaningful, lifelong
                        relationships through a trusted and secure matrimony platform.
                    </p>

                    <div class="social_media">

                        <a href="#" aria-label="Oppam Matrimony on Facebook">
                            <i class="fa fa-facebook" aria-hidden="true"></i>
                        </a>

                        <a href="#" aria-label="Oppam Matrimony on Twitter">
                            <i class="fa fa-twitter" aria-hidden="true"></i>
                        </a>

                        <a href="#" aria-label="Oppam Matrimony on LinkedIn">
                            <i class="fa fa-linkedin" aria-hidden="true"></i>
                        </a>

                        <a href="#" aria-label="Oppam Matrimony on YouTube">
                            <i class="fa fa-youtube-play" aria-hidden="true"></i>
                        </a>

                    </div>

                </div>

            </div>


            <!-- EXPLORE -->
            <div class="col-lg-2 col-md-6 col-6">

                <div class="footer-widget">

                    <h3>Explore</h3>

                    <ul class="fot-list">

                        <?php if (is_logged_in()): ?>

                            <li><a href="dashboard">Dashboard</a></li>
                            <li><a href="my-profile">My Profile</a></li>
                            <li><a href="daily-matches">Daily Matches</a></li>
                            <li><a href="all-profiles">All Profiles</a></li>
                            <li><a href="my-matches">My Matches</a></li>
                            <li><a href="search">Search</a></li>
                            <li><a href="interest">Interests</a></li>
                            <li><a href="package">Membership</a></li>

                        <?php else: ?>

                            <li><a href="./">Home</a></li>
                            <li><a href="register">Register Free</a></li>
                            <li><a href="login">Login</a></li>
                            <li><a href="package">Membership</a></li>
                            <li><a href="package#faq">How It Works</a></li>

                        <?php endif; ?>

                    </ul>

                </div>

            </div>


            <!-- HELP & SUPPORT -->
            <div class="col-lg-3 col-md-6 col-6">

                <div class="footer-widget">

                    <h3>Help &amp; Support</h3>

                    <ul class="fot-list">

                        <!-- "Wedding Success Stories" used to point at details.php — a MEMBER
                             PROFILE detail view ("Krishna Priya TS", "Do you like her?"), not a
                             success-stories page: a logged-out visitor clicking it landed inside
                             a stranger's profile. It was unlinked until a real page existed.
                             success-stories.php now does, and this is the public entry point. -->
                        <li><a href="success-stories">Wedding Success Stories</a></li>
                        <li><a href="package#faq">FAQ</a></li>
                        <li><a href="contact">Contact Us</a></li>
                        <li><a href="package">Membership Benefits</a></li>
                        <li><a href="about">About Us</a></li>
                        <li><a href="terms">Terms of Use</a></li>
                        <li><a href="privacy">Privacy Policy</a></li>

                    </ul>

                </div>

            </div>


            <!-- CONTACT -->
            <div class="col-lg-3 col-md-6">

                <div class="footer-widget footer-contact">

                    <h3>Get In Touch</h3>

                    <div class="office-icon-text">

                        <i class="fa fa-map-marker" aria-hidden="true"></i>

                        <address>
                            <a href="https://goo.gl/maps/" target="_blank" rel="noopener">
                                Sajaya 7A Block, First Floor,<br>
                                Office No: 10, Nad Al Sheba-3
                            </a>
                        </address>

                    </div>

                    <div class="office-icon-text">

                        <i class="fa fa-phone" aria-hidden="true"></i>

                        <div class="contact-links">
                            <a href="tel:+971551609872">+971 55 160 9872</a>
                            <a href="tel:042726787">+042 726 787</a>
                        </div>

                    </div>

                    <div class="office-icon-text">

                        <i class="fa fa-envelope" aria-hidden="true"></i>

                        <div class="contact-links">
                            <a href="mailto:support@matrimony.com">support@matrimony.com</a>
                            <a href="mailto:info@matrimony.com">info@matrimony.com</a>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- COPYRIGHT

             This used to carry a SECOND nav here — a .footer-menu of
             Privacy | FAQ | Contact | Created by Eyednext. Every one of those
             links already sits in the Help & Support column a few rows above, so
             on mobile the page ended in two stacked menus saying the same thing,
             right under the fixed tab bar: three menus in one screen.

             It also overflowed. The Bootstrap .row it sat in applies a -12px
             negative margin on each side, which punches past .copyright-area and
             made the strip 370px wide inside a 358px column at 390px — so the
             last item ("Created by Eyednext") sat off-screen and the strip
             scrolled sideways. See the row-gutter note in CLAUDE.md.

             So: no nav, and no .row. A plain flex strip has no negative margins
             and cannot overflow, and the credit is what it always was — an
             attribution line, not a destination. -->
        <div class="copyright-area">

            <p class="copyright-text">
                &copy; <?php ee(date('Y')); ?> Oppam Matrimony. All Rights Reserved.
            </p>

            <p class="footer-credit">Created by Eyednext</p>

        </div>

    </div>

</footer>
