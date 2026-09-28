<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Create Your Profile | Oppam Matrimony';
$page_desc     = 'Build a complete matrimonial profile and increase your chances of finding the right partner.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms, Kerala Matrimony Registration, Marriage Profile Creation, Online Matrimony Profile';
$og_title      = 'Create a Winning Matrimony Profile';
$og_desc       = 'Get started with a profile that attracts compatible matches.';
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
    <h1 class="visually-hidden">Create Your Profile</h1>

    <!-- /*================ PROFILE CREATION ================*/ -->

    <section class="register-section">
        <div class="container is-readable">
            <!-- Heading -->
            <div class="register-heading">
                <h2 class="profile-title">Profile Creation</h2>
                <p class="profile-subtitle">
                    Create your profile and begin your journey to find your perfect life partner.
                </p>
            </div>
            <div class="register-wrapper">
                <!-- Left Side -->
                <div class="register-left">

                    <div class="dashboard-title">
                        <h3>Create Profile</h3>
                        <p>Complete all steps to build your profile</p>
                    </div>

                    <div class="profile-steps">

                        <a href="profile-creation" class="step-item active">
                            <span class="step-number">1</span>
                            <div>
                                <h4>Profile Creation</h4>
                                <p>Basic information</p>
                            </div>
                        </a>

                        <a href="education" class="step-item">
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
                    <!-- TODO(backend): no handler yet. This step POSTS FORWARD to education.php so the demo
                             still navigates. Once a handler exists, point action back at profile-creation.php,
                             validate + persist, then redirect to education.php (POST/Redirect/GET) so a
                             validation error can re-render this step with the user's input intact.
                             Also guard step order: a user can deep-link straight to any step. -->
                        <form method="post" action="education">
                        <?php csrf_field(); ?>

                        <div class="row">

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group ">
                                    <label for="first_name">First Name *</label>
                                    <input id="first_name" name="first_name" type="text"
                                        class="form-control"
                                        placeholder="Enter First Name">
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="last_name">Last Name *</label>
                                    <input id="last_name" name="last_name" type="text"
                                        class="form-control"
                                        placeholder="Enter Last Name">
                                </div>
                            </div>

                        </div>
                        <div class="form-group">
                            <label for="dob">Date Of Birth *</label>
                            <input type="date" class="form-control" id="dob" name="dob">
                        </div>

                        <div class="row">

                            <!-- Height -->
                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="height">Height *</label>

                                    <select id="height" name="height" class="form-select">
                                        <option value="">Select Height</option>
                                        <option>4' 6" (137 cm)</option>
                                        <option>4' 8" (142 cm)</option>
                                        <option>5' 0" (152 cm)</option>
                                        <option>5' 2" (157 cm)</option>
                                        <option>5' 4" (162 cm)</option>
                                        <option>5' 6" (167 cm)</option>
                                        <option>5' 8" (172 cm)</option>
                                        <option>5' 10" (177 cm)</option>
                                        <option>6' 0" (182 cm)</option>
                                        <option>6' 2" (187 cm)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Weight -->
                            <div class="col-lg-6 col-md-6 col-sm-6 col-12">
                                <div class="form-group">
                                    <label for="weight">Weight *</label>

                                    <input id="weight" name="weight" type="number"
                                        class="form-control"
                                        placeholder="Enter Weight (Kg)">
                                </div>
                            </div>

                        </div>

                        <div class="form-group">
                            <label>Gender *</label>

                            <div class="option-btns">
                                <button type="button" class="active">Male</button>
                                <button type="button">Female</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Marital Status *</label>

                            <div class="option-btns">
                                <button type="button" class="active">Unmarried</button>
                                <button type="button">Divorced</button>
                                <button type="button">Separated</button>
                                <button type="button">Widow/Widower</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Religion *</label>

                            <div class="option-btns">
                                <button type="button">Christian</button>
                                <button type="button" class="active">Hindu</button>
                                <button type="button">Inter-Religion</button>
                                <button type="button">Jain</button>
                                <button type="button">Muslim</button>
                                <button type="button">No Religion</button>
                                <button type="button">Others</button>
                            </div>
                        </div>

                        <!-- Matching Profile Card -->

                        <!-- <div class="matching-card">
                            <div class="matching-icon">
                                <i class="fa fa-users"></i>
                            </div>

                            <div class="matching-content">
                                <h3>22647</h3>
                                <p>Matching Profiles</p>
                            </div>
                        </div> -->

                        <div class="form-group">
                            <label for="caste">Caste *</label>

                            <select id="caste" name="caste" class="form-select">
                                <option>Vishwakarma</option>
                                <option>Ezhava</option>
                                <option>Nair</option>
                                <option>Brahmin</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="star">Star *</label>

                            <select id="star" name="star" class="form-select">
                                <option>Aswathi</option>
                                <option>Bharani</option>
                                <option>Karthika</option>
                                <option>Rohini</option>
                            </select>
                        </div>

                        <!-- TODO(backend): OPTIONAL. If the member signed up through an agent,
                             they enter that agent's code here. Validate it server-side against the
                             broker table and store the broker's id on the profile row - never trust
                             this string as-is. An unknown code must NOT block the signup: save the
                             profile, flag the code for review. Leave blank = direct signup. -->
                        <div class="form-group">
                            <label for="broker_id">Broker ID</label>
                            <input id="broker_id" name="broker_id" type="text"
                                class="form-control"
                                placeholder="e.g. BRK1042"
                                autocomplete="off"
                                aria-describedby="broker_id_help">
                            <small id="broker_id_help" class="text-muted-brand">
                                Optional &mdash; only if an agent referred you. Leave blank otherwise.
                            </small>
                        </div>
                        <div class="row">
                            <div class="col-12 text-center ">
                                <button type="submit" class="view-btn">continue <i class="fa fa-arrow-right"></i></button>
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
