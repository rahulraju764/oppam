<?php
// PUBLIC page — a member who cannot log in is by definition logged out.
// TODO(backend): delete $is_public once is_logged_in() reads $_SESSION.
$is_public = true;
require_once __DIR__ . '/assets/includes/auth.php';

/* =============================================================================
   !! THIS PAGE SENDS NOTHING. !!
   =============================================================================
   It is the LAYOUT for password reset. There is no handler, no token, no mail.
   A reset form that looks real but is not will have people typing their address
   and waiting for an email that never arrives, so a visible .demo-banner says so.
   DO NOT REMOVE IT until the real flow exists.

   WHEN YOU BUILD THE REAL THING
     - ALWAYS respond the same way whether or not the address is registered.
       "No account with that email" is an account-enumeration oracle.
     - The token must be random (random_bytes, not uniqid/rand), single-use, and
       short-lived (an hour). Store a HASH of it, not the token itself — a leaked
       database should not be a set of live reset links.
     - Build the reset URL from a HARDCODED domain, not from site_url(): that
       function derives the host from the client's Host header, which is fine for
       a canonical tag and is a password-reset poisoning hole here. auth.php says
       the same thing at its own TODO.
     - Rate-limit by address AND by IP.
     - Invalidate existing sessions when the password actually changes.
   ============================================================================= */

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Reset Your Password | Oppam Matrimony';
$page_desc     = 'Reset the password for your Oppam Matrimony account.';
$page_keywords = 'Oppam Matrimony Login, Reset Password, Kerala Matrimony';
$og_title      = 'Reset Your Password';
$og_desc       = 'Get back into your Oppam Matrimony account.';
$page_robots   = 'noindex, nofollow';   // an account-recovery form has no business in search

?>
<!doctype html>
<html lang="en">

<head>
<?php include __DIR__ . '/assets/includes/head.php'; ?>
</head>

<body>

  <?php include_once('assets/includes/header.php') ?>

    <main id="main" tabindex="-1">

    <h1 class="visually-hidden">Reset your password</h1>

  <!-- Same .login-card shell as login.php — this page is a step in that flow, not
       a new kind of page, so it must not look like one. -->
  <section class="login-section">
    <div class="container is-readable">

      <div class="login-card">

        <div class="row g-0 align-items-stretch">

          <!-- VISUAL — hidden below lg, where it would only push the form down. -->
          <div class="col-lg-6 d-none d-lg-block">
            <div class="login-visual">
              <img src="assets/images/login/login-img2.jpg" alt="Newly married Kerala couple"
                   width="470" height="650" fetchpriority="high" decoding="async">
              <div class="login-visual-caption">
                <h2>It happens to everyone.</h2>
                <p>Enter the address on your account and we will send you a reset link.</p>
              </div>
            </div>
          </div>

          <!-- FORM -->
          <div class="col-lg-6 col-12">
            <div class="login-form">

              <div class="login-head">
                <h2>Reset your password</h2>
                <p>We will email you a link to choose a new one.</p>
              </div>

              <!-- DEMO BANNER — see the block at the top of this file. Do not remove
                   until the real flow exists. -->
              <div class="demo-banner" role="alert">
                <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <div>
                  <strong>Demonstration page — no email is sent.</strong>
                  <p>This form is a layout only. If you cannot get into your account,
                     please <a href="contact">contact us</a>.</p>
                </div>
              </div>

              <!-- TODO(backend): NO HANDLER — see the block at the top of this file
                   before wiring it, particularly the enumeration and token notes. -->
              <form method="post" action="forgot-password">
                <?php csrf_field(); ?>

                <div class="login-field">
                  <label for="resetEmail" class="login-label">Email address</label>
                  <input type="email" class="form-control login-input" id="resetEmail"
                         name="email" autocomplete="email"
                         placeholder="The address on your account">
                </div>

                <!-- A real <button type="submit">: there is no demo navigation to fake
                     here. It posts to a page with no handler and nothing happens. -->
                <button type="submit" class="btn login-btn">Send reset link</button>

                <p class="login-prompt">
                  Remembered it? <a href="login">Back to login</a>
                </p>

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
