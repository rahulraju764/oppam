<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Find Your Ideal Partner | Oppam Matrimony';
$page_desc     = 'Search for suitable brides and grooms based on age, education, profession, and preferences.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Find Your Perfect Partner';
$og_desc       = 'Meet compatible matches and build meaningful relationships.';
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
    <h1 class="visually-hidden">Partner Preferences</h1>

    <!-- partner Preference section -->

    <section class="register-section">
        <div class="container is-readable">
            <!-- Heading -->
            <div class="register-heading">
                <h2 class="profile-title">Partner Preference</h2>
                <p class="profile-subtitle">
                    Tell us about the qualities and preferences you seek in your ideal life partner.
                </p>
            </div>
            <div class="register-wrapper">
                <!-- Left Side -->
                <div class="register-left">

                    <div class="dashboard-title">
                        <h3>Partner Preference</h3>
                        <p>Tell us about your ideal life partner.</p>
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

                        <a href="partner" class="step-item active">
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
                        <a href="profile-photos" class="step-item">
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

                    <!-- TODO(backend): no handler yet. This step POSTS FORWARD to contact-details.php so the demo
                             still navigates. Once a handler exists, point action back at partner.php,
                             validate + persist, then redirect to contact-details.php (POST/Redirect/GET) so a
                             validation error can re-render this step with the user's input intact.
                             Also guard step order: a user can deep-link straight to any step. -->
                        <form method="post" action="contact-details">
                        <?php csrf_field(); ?>
                        <div class="row">

                            <!-- Basic & Personal Preference -->
                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <h4 class="preference-heading">
                                    Basic & Personal Preference
                                </h4>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Age *</label>

                                    <div class="row">
                                        <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                            <select id="age_from" name="age_from" aria-label="Age: From" class="form-select">
                                                <option>From</option>
                                                <option>18</option>
                                                <option>19</option>
                                                <option>20</option>
                                                <option>21</option>
                                                <option>22</option>
                                                <option>23</option>
                                                <option>24</option>
                                                <option>25</option>
                                                <option>26</option>
                                                <option>27</option>
                                                <option>28</option>
                                                <option>29</option>
                                                <option>30</option>
                                                <option>35</option>
                                                <option>40</option>
                                                <option>45</option>
                                                <option>50</option>
                                                <option>55</option>
                                                <option>60</option>
                                            </select>
                                        </div>

                                        <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                            <select id="age_to" name="age_to" aria-label="Age: To" class="form-select">
                                                <option>To</option>
                                                <option>18</option>
                                                <option>19</option>
                                                <option>20</option>
                                                <option>21</option>
                                                <option>22</option>
                                                <option>23</option>
                                                <option>24</option>
                                                <option>25</option>
                                                <option>26</option>
                                                <option>27</option>
                                                <option>28</option>
                                                <option>29</option>
                                                <option>30</option>
                                                <option>35</option>
                                                <option>40</option>
                                                <option>45</option>
                                                <option>50</option>
                                                <option>55</option>
                                                <option>60</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Height</label>

                                    <div class="row">
                                        <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                            <select id="height_from" name="height_from" aria-label="Height: From" class="form-select">
                                                <option>From</option>
                                                <option>4'5"</option>
                                                <option>5'0"</option>
                                                <option>5'5"</option>
                                                <option>6'0"</option>
                                            </select>
                                        </div>

                                        <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                            <select id="height_to" name="height_to" aria-label="Height: To" class="form-select">
                                                <option>To</option>
                                                <option>4'5"</option>
                                                <option>5'0"</option>
                                                <option>5'5"</option>
                                                <option>6'0"</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Marital Status</label>

                                    <div class="option-btns">
                                        <button type="button" class="active">Any</button>
                                        <button type="button">Unmarried</button>
                                        <button type="button">Divorced</button>
                                        <button type="button">Separated</button>
                                        <button type="button">Widow/Widower</button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Physical Status</label>

                                    <div class="option-btns">
                                        <button type="button" class="active">Any</button>
                                        <button type="button">Normal</button>
                                        <button type="button">Disabled</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Religious Preference -->
                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <h4 class="preference-heading">
                                    Religious Preference
                                </h4>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                <div class="form-group">
                                    <label for="religion">Religion *</label>

                                    <select id="religion" name="religion" class="form-select">
                                        <option>Select Religion</option>
                                        <option>Hindu</option>
                                        <option>Muslim</option>
                                        <option>Christian</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                <div class="form-group">
                                    <label for="caste">Caste</label>

                                    <select id="caste" name="caste" class="form-select">
                                        <option>Select Caste</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Education & Career -->
                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <h4 class="preference-heading">
                                    Education & Career
                                </h4>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="education">Education</label>

                                    <select id="education" name="education" class="form-select">
                                        <option>Select Education</option>
                                        <option>Bachelor Degree</option>
                                        <option>Master Degree</option>
                                        <option>PhD</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label for="occupation">Occupation</label>

                                    <input id="occupation" name="occupation" type="text"
                                        class="form-control"
                                        placeholder="Preferred Occupation">
                                </div>
                            </div>

                            <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                                <div class="form-group">
                                    <label>Annual Income</label>

                                    <div class="row">
                                        <div class="col-lg-4 col-md-4 col-sm-4 col-4">
                                            <select id="annual_income_inr" name="annual_income_inr" aria-label="Annual Income: INR" class="form-select">
                                                <option>INR</option>
                                            </select>
                                        </div>

                                        <div class="col-lg-8 col-md-8 col-sm-8 col-8">
                                            <select id="annual_income_select_income_range" name="annual_income_select_income_range" aria-label="Annual Income: Select Income Range" class="form-select">
                                                <option>Select Income Range</option>
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

                            <div class="col-12 text-center">
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
