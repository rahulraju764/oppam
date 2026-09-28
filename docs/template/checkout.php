<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';
require_once __DIR__ . '/assets/includes/plans.php';

/* =============================================================================
   !! THIS PAGE TAKES NO PAYMENT AND MUST NOT LOOK AS IF IT DOES. !!
   =============================================================================
   It is the LAYOUT for a checkout. There is no handler, no gateway, no order and
   no money. A payment form that looks real but is not is the one page on this
   skeleton that can actually harm someone, so:

     - A visible .demo-banner sits at the top of the page and says so. DO NOT
       REMOVE IT until real payment exists — it is the only thing standing between
       a demo build and a page that credibly asks for card details.
     - There are NO card fields. Not disabled ones, not placeholders. A card number
       input on an unwired page is a phishing form with good intentions; the real
       integration will redirect to the gateway's own hosted page anyway, so these
       fields should never exist here at all.

   WHEN YOU BUILD THE REAL THING
     - Never accept the price from the client. $_GET['plan'] selects a plan KEY;
       the amount comes from the server's own plan table (assets/includes/plans.php
       today), never from a form field.
     - Redirect to the gateway (Razorpay / PayU / Stripe) and let it collect the
       card. Do not proxy card data through this server — that pulls the whole app
       into PCI scope.
     - Confirm the order from the gateway's server-to-server webhook, not from the
       browser's return URL. The return URL is attacker-controllable.
     - Make the order idempotent so a double submit cannot charge twice.
   ============================================================================= */

/* Which plan is being bought. TODO(backend): read $_GET['plan'] and match it
   against $plans by key; this hardcodes the featured one. */
$selected = null;
foreach ($plans as $p) {
    if (!empty($p['featured'])) {
        $selected = $p;
        break;
    }
}
$selected = $selected ?? $plans[0];

/* Demo figures. TODO(backend): compute server-side from the plan table. */
$gst_rate = 0.18;
$sub_total = (float) str_replace(',', '', $selected['billed']);
$gst       = $sub_total * $gst_rate;
$total     = $sub_total + $gst;

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Checkout | Oppam Matrimony';
$page_desc     = 'Review your Oppam Matrimony membership plan before payment.';
$page_keywords = 'Oppam Matrimony Membership, Kerala Matrimony Plans';
$og_title      = 'Checkout';
$og_desc       = 'Review your membership plan.';
$page_robots   = 'noindex, nofollow';   // behind auth, and never a landing page

?>
<!doctype html>
<html lang="en">

<head>
<?php include __DIR__ . '/assets/includes/head.php'; ?>
</head>

<body>

    <?php include_once('assets/includes/header.php') ?>

    <main id="main" tabindex="-1">

    <section class="checkout-section">
        <div class="container is-readable">

            <h1 class="visually-hidden">Checkout</h1>

            <!-- DEMO BANNER — see the block at the top of this file. Do not remove
                 this until real payment exists. role="alert" so it is announced
                 rather than being a decoration a screen reader walks past. -->
            <div class="demo-banner" role="alert">
                <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <div>
                    <strong>Demonstration page — no payment is taken.</strong>
                    <p>This is a layout for the checkout, not a working one. Nothing is
                       charged, no order is created, and no card details are collected
                       anywhere on this page.</p>
                </div>
            </div>

            <div class="row g-4">

                <!-- ORDER SUMMARY -->
                <div class="col-lg-7 col-12">

                    <div class="checkout-card">

                        <h2>Your plan</h2>

                        <div class="checkout-plan">
                            <div>
                                <h3><?php ee($selected['name']); ?> Membership</h3>
                                <p class="text-muted-brand">12 months, billed once</p>
                            </div>
                            <p class="checkout-plan-price">
                                &#8377;<?php ee($selected['billed']); ?>
                            </p>
                        </div>

                        <ul class="checkout-features">
                            <?php foreach ($selected['features'] as $f): ?>
                                <li><i class="fa fa-check" aria-hidden="true"></i> <?php ee($f); ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <p class="checkout-change">
                            <a href="<?php ee(url('package.php')); ?>">Choose a different plan</a>
                        </p>

                    </div>

                </div>

                <!-- TOTALS -->
                <div class="col-lg-5 col-12">

                    <div class="checkout-card checkout-total">

                        <h2>Total</h2>

                        <dl class="checkout-lines">
                            <div>
                                <dt>Subtotal</dt>
                                <dd>&#8377;<?php ee(number_format($sub_total, 2)); ?></dd>
                            </div>
                            <div>
                                <dt>GST (18%)</dt>
                                <dd>&#8377;<?php ee(number_format($gst, 2)); ?></dd>
                            </div>
                            <div class="checkout-line-total">
                                <dt>Amount payable</dt>
                                <dd>&#8377;<?php ee(number_format($total, 2)); ?></dd>
                            </div>
                        </dl>

                        <!-- NO card fields, deliberately — see the block at the top of
                             this file. The real flow hands off to the gateway's own
                             hosted page from here.
                             The control is DISABLED because it genuinely does nothing;
                             an enabled button that silently no-ops is worse than one
                             that admits it. -->
                        <button type="button" class="btn login-btn" disabled>
                            Continue to payment
                        </button>

                        <p class="checkout-note text-muted-brand">
                            Payment is not enabled on this build. To buy a membership,
                            <a href="<?php ee(url('contact.php')); ?>">contact us</a>.
                        </p>

                        <p class="checkout-note text-muted-brand">
                            By subscribing you accept our
                            <a href="<?php ee(url('terms.php')); ?>">Terms of Use</a>.
                        </p>

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
