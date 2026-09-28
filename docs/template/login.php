<?php
// PUBLIC page — visitor is treated as logged out.
// TODO(backend): delete $is_public once is_logged_in() reads $_SESSION.
$is_public = true;
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Member Login | Oppam Matrimony Kerala';
$page_desc     = 'Securely access your Oppam Matrimony account to manage matches, profile updates, and interests.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms,Oppam Matrimony Login';
$og_title      = 'Login to Oppam Matrimony';
$og_desc       = 'Access your account and continue your search for the perfect life partner.';
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

    <!-- The design shows no page title here; this gives the document a
         proper top-level heading without changing anything visually. -->
    <h1 class="visually-hidden">Log in to Oppam Matrimony</h1>

  <!-- LOGIN -->
  <section class="login-section">
    <div class="container is-readable">

      <div class="login-card">

        <div class="row g-0 align-items-stretch">

          <!-- VISUAL — hidden on small screens, where it would just push the form down -->
          <div class="col-lg-6 d-none d-lg-block">
            <div class="login-visual">
              <img src="assets/images/login/login-img1.jpg" alt="Newly married Kerala couple" width="470" height="650" fetchpriority="high" decoding="async">
              <div class="login-visual-caption">
                <h3>Find someone who feels like home.</h3>
                <p>Thousands of verified Kerala profiles, and the next one could be yours.</p>
              </div>
            </div>
          </div>

          <!-- FORM -->
          <div class="col-lg-6 col-12">
            <div class="login-form">

              <div class="login-head">
                <h2>Welcome back</h2>
                <p>Log in to continue your search for the right match.</p>
              </div>

              <!-- TODO(backend): no handler yet. Handle $_POST here — validate the credentials,
                   session_start(), set $_SESSION['user_id'], then redirect to dashboard.php.
                   The two CTAs below are plain anchors so the demo can navigate; restore them to
                   <button type="submit"> once a real handler exists. -->
              <form method="post" action="login">
                <?php csrf_field(); ?>

                <div class="login-field">
                  <label for="loginId" class="login-label">Mobile No. / Email Id</label>
                  <input type="text" class="form-control login-input" id="loginId" name="login_id"
                    placeholder="Enter your mobile number or email">
                </div>

                <div class="login-field">
                  <label for="loginPassword" class="login-label">Password</label>
                  <input type="password" class="form-control login-input" id="loginPassword" name="password"
                    placeholder="Enter your password">
                </div>

                <!-- Forgot Password is its own link, OUTSIDE the checkbox label —
                     nesting it inside meant clicking it toggled the checkbox. -->
                <div class="login-meta">
                  <div class="form-check login-check">
                    <input type="checkbox" class="form-check-input" id="stayLoggedIn" name="stay_logged_in" value="1">
                    <label class="form-check-label" for="stayLoggedIn">Stay logged in</label>
                  </div>
                  <a href="forgot-password" class="login-forgot">Forgot password?</a>
                </div>

                <a href="dashboard" class="btn login-btn">Login</a>
                <a href="dashboard" class="btn login-btn-alt">Login with OTP</a>

                <p class="login-prompt">New to Oppam? <a href="register">Create an account</a></p>

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
