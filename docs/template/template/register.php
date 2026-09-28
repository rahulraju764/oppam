<?php
// PUBLIC page — visitor is treated as logged out.
// TODO(backend): delete $is_public once is_logged_in() reads $_SESSION.
$is_public = true;
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Matrimony Registration | Oppam Matrimony';
$page_desc     = 'Create your free profile on Oppam Matrimony and connect with verified Kerala bride and groom profiles for trusted matchmaking.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Start Your marriage Journey with Oppam Matrimony in Kerala';
$og_desc       = 'Register free on Oppam Matrimony and connect with genuine Kerala matrimony profiles for meaningful and trusted relationships.';
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

    <h1 class="visually-hidden">Create your free Oppam Matrimony profile</h1>

    <!-- REGISTER — same card shell as login.php: visual left, form right. -->
    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card reg-card">
                <div class="row g-0 align-items-stretch">

                    <!-- VISUAL — hidden on small screens, where it would only push the form down -->
                    <div class="col-lg-5 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="assets/images/login/login-img2.jpg" alt="Newly married Kerala couple" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h3>Your story starts with a profile.</h3>
                                <p>Free to join. Verified Kerala brides and grooms, matched with care.</p>
                            </div>
                        </div>
                    </div>

                    <!-- FORM -->
                    <div class="col-lg-7 col-12">
                        <div class="login-form">

                            <div class="login-head">
                                <h2>Register free</h2>
                                <p>A few details now — you can complete the rest of your profile next.</p>
                            </div>

                            <!-- TODO(backend): no handler yet. Handle $_POST here — create the user,
                                 session_start(), set $_SESSION['user_id'], then redirect to profile-creation.php.
                                 The Register button below is a plain anchor so the demo can walk into the
                                 wizard; restore it to <button type="submit"> once a real handler exists. -->
                            <form method="post" action="register">
                                <?php csrf_field(); ?>

                                <div class="row reg-grid">

                                    <div class="col-md-6 col-12">
                                        <div class="login-field">
                                            <label for="profileFor" class="login-label">Matrimony profile for</label>
                                            <select id="profileFor" name="profile_for" class="form-select login-input">
                                                <option value="">Select an option</option>
                                                <option value="self">Myself</option>
                                                <option value="son">My son</option>
                                                <option value="daughter">My daughter</option>
                                                <option value="brother">My brother</option>
                                                <option value="sister">My sister</option>
                                                <option value="relative">A relative / friend</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-12">
                                        <div class="login-field">
                                            <label for="regName" class="login-label">Name</label>
                                            <input type="text" id="regName" name="name" class="form-control login-input"
                                                placeholder="Full name" required>
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-12">
                                        <div class="login-field">
                                            <label for="regMobile" class="login-label">Mobile number</label>
                                            <input type="tel" id="regMobile" name="mobile" class="form-control login-input"
                                                placeholder="10-digit mobile number" required>
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-12">
                                        <div class="login-field">
                                            <label for="regEmail" class="login-label">Email</label>
                                            <input type="email" id="regEmail" name="email" class="form-control login-input"
                                                placeholder="you@example.com" required>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="login-field">
                                            <label for="regPassword" class="login-label">Password</label>
                                            <input type="password" id="regPassword" name="password"
                                                class="form-control login-input" placeholder="At least 8 characters" required>
                                        </div>
                                    </div>

                                </div>

                                <div class="login-meta reg-meta">
                                    <div class="form-check login-check">
                                        <input type="checkbox" class="form-check-input" id="regTerms" name="terms" value="1">
                                        <label class="form-check-label" for="regTerms">
                                            <!-- Both documents, matching the checkbox on index.php's
                                                 hero form — a registration consent that names only
                                                 one of the two is not the consent being collected. -->
                                            I agree to the <a href="terms" class="login-forgot">terms of use</a>
                                            and <a href="privacy" class="login-forgot">privacy policy</a>
                                        </label>
                                    </div>
                                </div>

                                <a href="profile-creation" class="btn login-btn">Register</a>

                                <p class="login-prompt">Already a member? <a href="login">Login</a></p>

                            </form>

                        </div>
                    </div>

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
