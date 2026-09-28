<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Profile Photos | Oppam Matrimony';
$page_desc     = 'Upload quality profile photos and improve your visibility among potential matches.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Add Photos to Your Profile';
$og_desc       = 'Create a strong first impression with attractive profile photos.';
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
    <h1 class="visually-hidden">Profile Photos</h1>

    <!-- partner Preference section -->

    <section class="register-section">
        <div class="container is-readable">
            <!-- Heading -->
            <div class="register-heading">
                <h2 class="profile-title">Upload Photos</h2>
                <p class="profile-subtitle">
                    Add your photos to complete your profile and create a great first impression.
                </p>
            </div>
            <div class="register-wrapper">
                <!-- Left Side -->
                <div class="register-left">

                    <div class="dashboard-title">
                        <h3>Upload Photos</h3>
                        <p>Add clear and recent photos to complete your profile.</p>
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

                        <a href="contact-details" class="step-item ">
                            <span class="step-number">5</span>
                            <div>
                                <h4>Contact Details</h4>
                                <p>Add Contact Details</p>
                            </div>
                        </a>
                        <a href="profile-photos" class="step-item active">
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
                    <!-- TODO(backend): no handler yet. This step POSTS FORWARD to dashboard.php so the demo
                             still navigates. Once a handler exists, point action back at profile-photos.php,
                             validate + persist, then redirect to dashboard.php (POST/Redirect/GET) so a
                             validation error can re-render this step with the user's input intact.
                             Also guard step order: a user can deep-link straight to any step. -->
                        <form method="post" action="dashboard" enctype="multipart/form-data">
                        <?php csrf_field(); ?>
                        <div class="row">

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="profile_photo">Profile Photo *</label>
                                    <input id="profile_photo" name="profile_photo" type="file"
                                        class="form-control"
                                        accept="image/*">
                                    <small>Upload your primary profile photo.</small>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="additional_photos">Additional Photos</label>
                                    <input id="additional_photos" name="additional_photos" type="file"
                                        class="form-control"
                                        accept="image/*"
                                        multiple>
                                    <small>You can upload up to 5 additional photos.</small>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="photo_visibility">Photo Visibility</label>
                                    <select id="photo_visibility" name="photo_visibility" class="form-select">
                                        <option selected disabled>Select Visibility</option>
                                        <option>Visible to Everyone</option>
                                        <option>Visible to Registered Members Only</option>
                                        <option>Visible on Request</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="photo_caption">Photo Caption</label>
                                    <input id="photo_caption" name="photo_caption" type="text"
                                        class="form-control"
                                        placeholder="Enter a short caption">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 text-center ">
                                    <!-- TODO(backend): mark the profile complete, then redirect to dashboard.php. -->
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
