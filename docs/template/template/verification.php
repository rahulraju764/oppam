<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* =============================================================================
   TODO(backend): demo data. Nothing here is wired.

   THE UPLOAD FORM DOES NOT UPLOAD. It has enctype and a file input so the markup
   is right, but there is no handler and nothing is stored. When you build it:

     - Validate server-side (type, size, and that it IS an image/PDF). Never trust
       the client's Content-Type or the file extension.
     - Store OUTSIDE the web root. An identity document under htdocs/ is a public
       URL the moment someone guesses the filename.
     - Keep only as long as the check needs, then delete. This is the most
       sensitive data the site will ever hold.

   $steps['state'] drives the whole page: 'done' | 'active' | 'todo'.
   ============================================================================= */
$steps = [
    ['state' => 'done',   'title' => 'Email confirmed',
     'text'  => 'sally.roberts@example.com — confirmed 12 March 2024.'],
    ['state' => 'done',   'title' => 'Mobile number confirmed',
     'text'  => '+91 •••• ••• 872 — confirmed 12 March 2024.'],
    ['state' => 'active', 'title' => 'Government ID',
     'text'  => 'Upload one photo ID so we can confirm your name and date of birth.'],
    ['state' => 'todo',   'title' => 'Verified badge on your profile',
     'text'  => 'Added automatically once the ID check passes. Usually within two working days.'],
];

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Verify Your Profile | Oppam Matrimony';
$page_desc     = 'Verify your identity to get the verified badge on your Oppam Matrimony profile.';
$page_keywords = 'Kerala Matrimony, Oppam Matrimony, Profile Verification, Verified Matrimony Profile';
$og_title      = 'Get Your Profile Verified';
$og_desc       = 'A verified badge tells families your profile has been checked.';
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

    <!-- =========================
        VERIFICATION — the standard 3 / 6 / 3.
        Left rail carries the "why", centre carries the steps and the upload.
    ========================= -->

    <section class="dashboard-section verification-section">

        <div class="container">

            <div class="row dashboard-row">

                <!-- =========================
                    LEFT RAIL — why this is worth doing
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <div class="side-bar vf-why">

                        <h2>Why verify?</h2>

                        <ul class="vf-why-list">
                            <li><i class="fa fa-check" aria-hidden="true"></i>
                                A verified badge sits on your profile and in every search result.</li>
                            <li><i class="fa fa-check" aria-hidden="true"></i>
                                Families filter for verified profiles — yours gets seen more.</li>
                            <li><i class="fa fa-check" aria-hidden="true"></i>
                                Your document is seen by our verification team and nobody else.
                                It is never shown on your profile.</li>
                        </ul>

                        <p class="vf-why-note text-muted-brand">
                            Read how we handle your data in our
                            <a href="<?php ee(url('privacy.php')); ?>">Privacy Policy</a>.
                        </p>

                    </div>

                </div>

                <!-- =========================
                    CENTRE — steps + upload
                ========================= -->

                <div class="col-lg-6 col-md-12 col-sm-12 col-12">

                    <div class="dashboard-content mb-4">

                        <div class="content-header">
                            <h1>Verify your profile</h1>
                            <p>Two of four steps done.</p>
                        </div>

                        <!-- An ordered list, because these steps happen in order. The
                             state is on the <li> and repeated in text for AT — a green
                             tick alone is colour-as-only-meaning. -->
                        <ol class="vf-steps">
                            <?php foreach ($steps as $s): ?>
                                <li class="vf-step is-<?php ee($s['state']); ?>">

                                    <span class="vf-step-mark" aria-hidden="true">
                                        <?php if ($s['state'] === 'done'): ?>
                                            <i class="fa fa-check"></i>
                                        <?php else: ?>
                                            <i class="fa fa-circle-o"></i>
                                        <?php endif; ?>
                                    </span>

                                    <span class="vf-step-body">
                                        <span class="vf-step-title">
                                            <?php ee($s['title']); ?>
                                            <span class="chip vf-step-state">
                                                <?php
                                                $labels = ['done' => 'Done', 'active' => 'Next', 'todo' => 'Waiting'];
                                                ee($labels[$s['state']] ?? '');
                                                ?>
                                            </span>
                                        </span>
                                        <span class="vf-step-text"><?php ee($s['text']); ?></span>
                                    </span>

                                </li>
                            <?php endforeach; ?>
                        </ol>

                    </div>

                    <div class="dashboard-content">

                        <div class="content-header">
                            <h2>Upload your ID</h2>
                            <p>Aadhaar, passport, driving licence or voter ID. JPG, PNG or PDF, up to 5&nbsp;MB.</p>
                        </div>

                        <!-- TODO(backend): NO HANDLER. This form posts to itself and nothing
                             happens — see the block at the top of this file before wiring it.
                             enctype is set correctly so the markup does not have to change. -->
                        <form class="vf-form" method="post" action="<?php ee(url('verification.php')); ?>"
                              enctype="multipart/form-data">
                            <?php csrf_field(); ?>

                            <div class="vf-field">
                                <label for="vfDocType" class="login-label">Document type</label>
                                <select class="form-select login-input" id="vfDocType" name="doc_type">
                                    <option value="">Choose a document</option>
                                    <option value="aadhaar">Aadhaar card</option>
                                    <option value="passport">Passport</option>
                                    <option value="licence">Driving licence</option>
                                    <option value="voter">Voter ID</option>
                                </select>
                            </div>

                            <div class="vf-field">
                                <label for="vfDocNumber" class="login-label">Document number</label>
                                <input type="text" class="form-control login-input" id="vfDocNumber"
                                       name="doc_number" placeholder="As printed on the document"
                                       autocomplete="off">
                            </div>

                            <div class="vf-field">
                                <label for="vfDocFile" class="login-label">Upload a photo or scan</label>
                                <input type="file" class="form-control login-input" id="vfDocFile"
                                       name="doc_file" accept="image/jpeg,image/png,application/pdf">
                                <p class="vf-hint text-muted-brand">
                                    Make sure your name and date of birth are readable. You can cover
                                    everything else.
                                </p>
                            </div>

                            <div class="form-check vf-check">
                                <input type="checkbox" class="form-check-input" id="vfConsent"
                                       name="consent" value="1">
                                <label class="form-check-label" for="vfConsent">
                                    I confirm this document is mine and agree to it being used to
                                    verify my profile.
                                </label>
                            </div>

                            <!-- A real <button type="submit">: there is no demo navigation to
                                 fake here, so the honest control is the real one. It simply
                                 posts to a page with no handler. -->
                            <button type="submit" class="btn login-btn">Submit for verification</button>

                        </form>

                    </div>

                </div>

                <!-- =========================
                    RIGHT RAIL — shared promos
                ========================= -->

                <div class="col-lg-3 col-md-12 col-sm-12 col-12">

                    <?php include_once('assets/includes/ads.php') ?>

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
