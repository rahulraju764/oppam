<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Contact Details | Oppam Matrimony';
$page_desc     = 'Access verified contact details of compatible profiles according to membership eligibility.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Connect with Matches';
$og_desc       = 'Reach out and start meaningful conversations with potential partners.';
$page_robots   = 'noindex, nofollow';   // behind auth / names a member

?>
<!doctype html>
<html lang="en">

<head>
<?php include __DIR__ . '/assets/includes/head.php'; ?>
</head>

<body>

    <?php include_once('assets/includes/header.php') ?>

    <main id="main" tabindex="-1">

    <!-- The design shows no page title here; this gives the document a
         proper top-level heading without changing anything visually. -->
    <h1 class="visually-hidden">Contact Details</h1>

    <!-- partner Preference section -->

    <section class="register-section">
        <div class="container is-readable">
            <!-- Heading -->
            <div class="register-heading">
                <h2 class="profile-title">Contact Details</h2>
                <p class="profile-subtitle">
                    Share your contact information so matches can reach you.
                </p>
            </div>
            <div class="register-wrapper">
                <!-- Left Side -->
                <div class="register-left">

                    <div class="dashboard-title">
                        <h3>Contact Details</h3>
                        <p>Add your contact information.</p>
                    </div>

                    <div class="profile-steps">

                        <a href="profile-creation" class="step-item">
                            <span class="step-number">1</span>
                            <div>
                                <h4>Profile Creation</h4>
                                <p>Basic information</p>
                            </div>
                        </a>

                        <a href="education" class="step-item ">
                            <span class="step-number">2</span>
                            <div>
                                <h4>Education Details</h4>
                                <p>Academic information</p>
                            </div>
                        </a>

                        <a href="family" class="step-item ">
                            <span class="step-number">3</span>
                            <div>
                                <h4>Family Details</h4>
                                <p>Family background</p>
                            </div>
                        </a>

                        <a href="partner" class="step-item ">
                            <span class="step-number">4</span>
                            <div>
                                <h4>Partner Preference</h4>
                                <p>Expected life partner</p>
                            </div>
                        </a>

                        <a href="contact-details" class="step-item active">
                            <span class="step-number">5</span>
                            <div>
                                <h4>Contact Details</h4>
                                <p>Add Contact Details</p>
                            </div>
                        </a>
                        <a href="profile-photos" class="step-item ">
                            <span class="step-number">6</span>
                            <div>
                                <h4>Profile Photos</h4>
                                <p>Upload your pictures</p>
                            </div>
                        </a>
                    </div>

                </div>

                <!-- Right Side -->
                <div class="register-right">
                    <!-- TODO(backend): no handler yet. This step POSTS FORWARD to profile-photos.php so the demo
                             still navigates. Once a handler exists, point action back at contact-details.php,
                             validate + persist, then redirect to profile-photos.php (POST/Redirect/GET) so a
                             validation error can re-render this step with the user's input intact.
                             Also guard step order: a user can deep-link straight to any step. -->
                        <form method="post" action="profile-photos">
                        <?php csrf_field(); ?>
                        <div class="row">

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="mobile_number">Mobile Number *</label>
                                    <input id="mobile_number" name="mobile_number" type="tel" class="form-control"
                                        placeholder="Enter Mobile Number">
                                </div>
                            </div>

                             <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="email_address">Email Address *</label>
                                    <input id="email_address" name="email_address" type="email" class="form-control"
                                        placeholder="Enter Email Address">
                                </div>
                            </div>

                             <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="alternate_mobile_number">Alternate Mobile Number</label>
                                    <input id="alternate_mobile_number" name="alternate_mobile_number" type="tel" class="form-control"
                                        placeholder="Enter Alternate Mobile Number">
                                </div>
                            </div>

                             <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="country">Country *</label>
                                    <select id="country" name="country" class="form-select">
                                        <option selected disabled>Select Country</option>
                                        <option>India</option>
                                        <option>UAE</option>
                                        <option>Saudi Arabia</option>
                                        <option>Qatar</option>
                                        <option>Kuwait</option>
                                        <option>Oman</option>
                                        <option>Bahrain</option>
                                    </select>
                                </div>
                            </div>

                             <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="state">State *</label>
                                    <select id="state" name="state" class="form-select">
                                        <option selected disabled>Select State</option>
                                        <option>Kerala</option>
                                        <option>Tamil Nadu</option>
                                        <option>Karnataka</option>
                                        <option>Maharashtra</option>
                                        <option>Delhi</option>
                                        <option>Telangana</option>
                                        <option>Andhra Pradesh</option>
                                        <option>Gujarat</option>
                                    </select>
                                </div>
                            </div>

                             <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="city">City *</label>
                                    <select id="city" name="city" class="form-select">
                                        <option selected disabled>Select City</option>
                                        <option>Kochi</option>
                                        <option>Thiruvananthapuram</option>
                                        <option>Kozhikode</option>
                                        <option>Thrissur</option>
                                        <option>Kottayam</option>
                                        <option>Kannur</option>
                                        <option>Palakkad</option>
                                        <option>Malappuram</option>
                                    </select>
                                </div>
                            </div>

                             <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Address</label>
                                    <textarea class="form-control"
                                        rows="4"
                                        placeholder="Enter Address"></textarea>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 text-center ">
                                    <button type="submit" class="view-btn">continue <i class="fa fa-arrow-right"></i></button>
                                </div>
                            </div>

                        </div>
                    </form>
                </div>

            </div>
        </div>
    </section>


    </main>

    <?php include_once('assets/includes/footer.php') ?>
    <?php include_once('assets/includes/footer2.php') ?>
    <?php include_once('assets/includes/script.php') ?>
</body>

</html>
