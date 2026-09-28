<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Family Information | Oppam Matrimony';
$page_desc     = 'Learn about family background, values, and traditions of potential matches.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Family Matters in Marriage';
$og_desc       = 'Understand family details and compatibility before connecting.';
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
    <h1 class="visually-hidden">Family Details</h1>

    <section class="register-section">
        <div class="container is-readable">
            <!-- Heading -->
            <div class="register-heading">
                <h2 class="profile-title">Family Details</h2>
                <p class="profile-subtitle">
                    Share information about your family background to help create a complete profile.
                </p>
            </div>
            <div class="register-wrapper">
                <!-- Left Side -->
                <div class="register-left">

                    <div class="dashboard-title">
                        <h3>Family Details</h3>
                        <p>Provide information about your family background.</p>
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

                        <a href="family" class="step-item active">
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
                <!-- Right Side -->
                <div class="register-right">

                    <!-- TODO(backend): no handler yet. This step POSTS FORWARD to partner.php so the demo
                             still navigates. Once a handler exists, point action back at family.php,
                             validate + persist, then redirect to partner.php (POST/Redirect/GET) so a
                             validation error can re-render this step with the user's input intact.
                             Also guard step order: a user can deep-link straight to any step. -->
                        <form method="post" action="partner">
                        <?php csrf_field(); ?>
                        <div class="row">

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="father_s_name">Father's Name *</label>
                                    <input id="father_s_name" name="father_s_name" type="text" class="form-control" placeholder="Enter Father's Name">
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="father_s_occupation">Father's Occupation *</label>
                                    <input id="father_s_occupation" name="father_s_occupation" type="text" class="form-control" placeholder="Enter Occupation">
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="mother_s_name">Mother's Name *</label>
                                    <input id="mother_s_name" name="mother_s_name" type="text" class="form-control" placeholder="Enter Mother's Name">
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="mother_s_occupation">Mother's Occupation *</label>
                                    <input id="mother_s_occupation" name="mother_s_occupation" type="text" class="form-control" placeholder="Enter Occupation">
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="number_of_brothers">Number of Brothers *</label>
                                    <input id="number_of_brothers" name="number_of_brothers" type="number" class="form-control" placeholder="Enter Number">
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="number_of_sisters">Number of Sisters *</label>
                                    <input id="number_of_sisters" name="number_of_sisters" type="number" class="form-control" placeholder="Enter Number">
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="family_status">Family Status *</label>
                                    <select id="family_status" name="family_status" class="form-select">
                                        <option selected disabled>Select Status</option>
                                        <option>Middle Class</option>
                                        <option>Upper Middle Class</option>
                                        <option>Rich</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-12 text-center ">
                                <button type="submit" class="view-btn">
                                    Continue <i class="fa fa-arrow-right"></i>
                                </button>
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
