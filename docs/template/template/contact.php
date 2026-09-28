<?php
// MEMBER page — deliberately NOT $is_public, despite being linked from the
// logged-out nav too. Contact is dual-audience: it sits in the member profile
// dropdown (header.php) as well as the public nav and the footer.
//
// It carried $is_public = true for a while, to spare a logged-out visitor the
// member header. But is_logged_in() drives the mobile tab bar as well as the
// header, so the flag also stripped the bar — leaving a member on Contact Us
// with NO bottom navigation at all, the only such page in the app. privacy.php
// is linked from the same footer and never took the flag, so the two static
// pages disagreed with each other.
//
// The trade-off is now the same one every other member page already makes:
// while the site is a static demo, a logged-out visitor sees the member header
// here. That disappears the moment is_logged_in() reads $_SESSION — at which
// point this page needs no flag and no change.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Contact Us | Oppam Matrimony Kerala';
$page_desc     = 'Reach our support team for assistance regarding registration, membership, or profile management.';
$page_keywords = 'Contact Matrimony, Matrimony Help Center, Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Need Assistance?';
$og_desc       = 'Our support team is here to help with all your matrimonial needs.';
$page_robots   = 'index, follow';

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
        CONTACT
        Containers are fluid site-wide, but a form stretched to 1800px is
        unusable — so this one opts into the readable cap (.is-readable).
        Layout: centred page head, then form (7) alongside the details (5).
    ========================= -->

    <section class="contact-section">

        <div class="container is-readable">

            <!-- PAGE HEAD -->

            <div class="contact-head">
                <span class="eyebrow">Contact Us</span>
                <h1>Get in Touch</h1>
                <p>Questions about registration, membership or your profile? Our support team will get back to you within one working day.</p>
            </div>


            <div class="row g-4">

                <!-- FORM -->

                <div class="col-lg-7">

                    <!-- TODO(backend): no handler. This was not a <form> at all — the
                         Submit was an <a href="#">, so nothing could ever be sent. -->

                    <form class="matrimony-forms" action="contact" method="post">
                        <?php csrf_field(); ?>

                        <div class="row g-4">

                            <div class="col-md-6">
                                <label class="mat-label" for="first-name">First name</label>
                                <input type="text" class="form-control mat-text" id="first-name" name="first_name"
                                    placeholder="First name" required>
                            </div>

                            <div class="col-md-6">
                                <label class="mat-label" for="last-name">Last name</label>
                                <input type="text" class="form-control mat-text" id="last-name" name="last_name"
                                    placeholder="Last name">
                            </div>

                            <div class="col-12">
                                <label class="mat-label" for="email">Email</label>
                                <!-- Was type="text" with placeholder AND aria-label of "First name" —
                                     a copy-paste of the field above. -->
                                <input type="email" class="form-control mat-text" id="email" name="email"
                                    placeholder="you@example.com" required>
                            </div>

                            <div class="col-12">
                                <label class="mat-label" for="subject">Subject</label>
                                <select class="form-select mat-text" id="subject" name="subject">
                                    <option>General enquiry</option>
                                    <option>Registration help</option>
                                    <option>Membership &amp; payments</option>
                                    <option>Report a profile</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="mat-label" for="message">Message</label>
                                <!-- The textarea used to carry TWO labels ("Message" and "Comments"). -->
                                <textarea class="form-control mat-text" id="message" name="message" rows="5"
                                    placeholder="How can we help?" required></textarea>
                            </div>

                        </div>

                        <div class="contact-button">
                            <button type="submit">
                                <i class="fa fa-paper-plane" aria-hidden="true"></i>
                                Send Message
                            </button>
                        </div>

                    </form>

                </div>


                <!-- DETAILS -->

                <div class="col-lg-5">

                    <div class="contact-aside">

                        <!-- TODO(backend): these details contradict the footer, which lists a
                             Dubai address and support@matrimony.com. Pick one source of truth. -->

                        <div class="location">

                            <span class="location-icon">
                                <i class="fa fa-phone" aria-hidden="true"></i>
                            </span>

                            <div class="location-body">
                                <h3>Contact</h3>
                                <ul class="location-content">
                                    <li><a href="mailto:contact@domain.com">contact@domain.com</a></li>
                                    <li><a href="tel:089538812873">0895 - 3881 - 2873</a></li>
                                </ul>
                            </div>

                        </div>

                        <div class="location">

                            <span class="location-icon">
                                <i class="fa fa-map-marker" aria-hidden="true"></i>
                            </span>

                            <div class="location-body">
                                <h3>Address</h3>
                                <ul class="location-content">
                                    <li>1st floor 272-3, near St George Basilica church,<br>Angamaly, Kerala 683572</li>
                                </ul>
                            </div>

                        </div>

                        <div class="location">

                            <span class="location-icon">
                                <i class="fa fa-clock-o" aria-hidden="true"></i>
                            </span>

                            <div class="location-body">
                                <h3>Office Hours</h3>
                                <ul class="location-content">
                                    <li>Monday – Saturday, 9:30am – 6:30pm</li>
                                </ul>
                            </div>

                        </div>

                        <div class="social-media-links">

                            <h2>Follow More</h2>

                            <div class="social_media">
                                <a href="#" aria-label="Oppam Matrimony on Facebook"><i class="fa fa-facebook" aria-hidden="true"></i></a>
                                <a href="#" aria-label="Oppam Matrimony on Twitter"><i class="fa fa-twitter" aria-hidden="true"></i></a>
                                <a href="#" aria-label="Oppam Matrimony on Instagram"><i class="fa fa-instagram" aria-hidden="true"></i></a>
                                <a href="#" aria-label="Oppam Matrimony on LinkedIn"><i class="fa fa-linkedin" aria-hidden="true"></i></a>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- MAP — deliberately full-bleed, outside the readable container. -->

    <section class="map-section">
        <iframe
            title="Oppam Matrimony office location, Angamaly, Kerala"
            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d125655.32334806089!2d76.2986082794851!3d10.202660579800396!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3b080665e0bb9959%3A0x19b75e6b4e671ef1!2sAngamaly%2C%20Kerala!5e0!3m2!1sen!2sin!4v1780653618173!5m2!1sen!2sin"
            width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"></iframe>
    </section>

    </main>

    <?php include_once('assets/includes/footer.php') ?>
    <?php include_once('assets/includes/footer2.php') ?>
    <?php include_once('assets/includes/script.php') ?>
</body>

</html>
