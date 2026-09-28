<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Education Information | Oppam Matrimony';
$page_desc     = 'Browse profiles based on educational qualifications and academic achievements.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Find Educated Matches';
$og_desc       = 'Connect with profiles matching your educational preferences.';
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
    <h1 class="visually-hidden">Education & Career</h1>

    <!-- /*================ EDUCATION PAGE ================*/ -->

    <section class="register-section">
        <div class="container is-readable">
            <!-- Heading -->
            <div class="register-heading">
                <h2 class="profile-title">Education Details</h2>
                <p class="profile-subtitle">
                    Share your educational qualifications and professional details to help others know you better.
                </p>
            </div>
            <div class="register-wrapper">
                <!-- Left Side -->
                <div class="register-left">

                    <div class="dashboard-title">
                        <h3>Education Details</h3>
                        <p>Add your academic and professional information.</p>
                    </div>

                    <div class="profile-steps">

                        <a href="profile-creation" class="step-item">
                            <span class="step-number">1</span>
                            <div>
                                <h4>Profile Creation</h4>
                                <p>Basic information</p>
                            </div>
                        </a>

                        <a href="education" class="step-item active">
                            <span class="step-number">2</span>
                            <div>
                                <h4>Education Details</h4>
                                <p>Academic information</p>
                            </div>
                        </a>

                        <a href="family" class="step-item">
                            <span class="step-number">3</span>
                            <div>
                                <h4>Family Details</h4>
                                <p>Family background</p>
                            </div>
                        </a>

                        <a href="partner" class="step-item">
                            <span class="step-number">4</span>
                            <div>
                                <h4>Partner Preference</h4>
                                <p>Expected life partner</p>
                            </div>
                        </a>

                        <a href="contact-details" class="step-item">
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
                    <!-- TODO(backend): no handler yet. This step POSTS FORWARD to family.php so the demo
                             still navigates. Once a handler exists, point action back at education.php,
                             validate + persist, then redirect to family.php (POST/Redirect/GET) so a
                             validation error can re-render this step with the user's input intact.
                             Also guard step order: a user can deep-link straight to any step. -->
                        <form method="post" action="family">
                        <?php csrf_field(); ?>
                        <div class="row">

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="highest_education">Highest Education *</label>
                                    <select id="highest_education" name="highest_education" class="form-select">
                                        <option>Select Qualification</option>
                                        <option>SSLC</option>
                                        <option>Plus Two</option>
                                        <option>Diploma</option>
                                        <option>Bachelor Degree</option>
                                        <option>Master Degree</option>
                                        <option>PhD</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Employed In *</label>

                                    <div class="option-btns">
                                        <button type="button" class="active">Business</button>
                                        <button type="button">Defence</button>
                                        <button type="button">Government/PSU</button>
                                        <button type="button">MNC</button>
                                        <button type="button">Not Working</button>
                                        <button type="button">Private</button>
                                        <button type="button">Self Employed</button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="occupation">Occupation *</label>
                                    <select id="occupation" name="occupation" class="form-select">
                                        <option>Select Occupation</option>
                                        <option>Web Developer</option>
                                        <option>Doctor</option>
                                        <option>Engineer</option>
                                        <option>Teacher</option>
                                        <option>Business</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Annual Income *</label>

                                    <div class="row">
                                        <div class="col-md-4 col-sm-6 col-6">
                                            <select id="annual_income_indian_rupee" name="annual_income_indian_rupee" aria-label="Annual Income: Indian Rupee" class="form-select">
                                                <option>Indian Rupee</option>
                                                <option>USD</option>
                                                <option>AED</option>
                                            </select>
                                        </div>

                                        <div class="col-md-8 col-sm-6 col-6">
                                            <select id="annual_income_select_income" name="annual_income_select_income" aria-label="Annual Income: Select Income" class="form-select">
                                                <option>Select Income</option>
                                                <option>Below 2 Lakhs</option>
                                                <option>2 - 5 Lakhs</option>
                                                <option>5 - 10 Lakhs</option>
                                                <option>10 - 20 Lakhs</option>
                                                <option>Above 20 Lakhs</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <div class="col-lg-12 col-md-6 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="country_living_in">Country Living In *</label>
                                    <select id="country_living_in" name="country_living_in" class="form-select">
                                        <option>Select Country</option>
                                        <option>India</option>
                                        <option>UAE</option>
                                        <option>USA</option>
                                        <option>Canada</option>
                                        <option>UK</option>
                                        <option>Australia</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-6 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="current_location">Current Location *</label>
                                    <select id="current_location" name="current_location" class="form-select">
                                        <option>Select Current Location</option>
                                        <option>Kerala - Ernakulam</option>
                                        <option>Kerala - Kottayam</option>
                                        <option>Kerala - Kozhikode</option>
                                        <option>Kerala - Trivandrum</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="permanent_location">Permanent Location *</label>
                                    <select id="permanent_location" name="permanent_location" class="form-select">
                                        <option>Select Permanent Location</option>
                                        <option>India</option>
                                        <option>UAE</option>
                                        <option>USA</option>
                                        <option>Canada</option>
                                    </select>
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
