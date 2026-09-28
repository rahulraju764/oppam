<?php
/* The three pricing cards. Rendered from $plans (assets/includes/plans.php).

   $plan_cta_url decides where "Purchase Now" goes, and is the ONLY thing that differs
   between the two pages that show these cards:
     index.php   -> package.php    (the landing page teaser sends you to the plans page)
     package.php -> checkout.php   (the plan the member picked)

   NOTE: checkout.php takes NO payment and says so on the page. Read the block at
   the top of it before wiring a gateway.

   Set it before including this file; it falls back to package.php. */

require_once __DIR__ . '/plans.php';

$plan_cta_url = $plan_cta_url ?? 'package.php';
?>

<div class="row justify-content-center pricing-row">

    <?php foreach ($plans as $plan): ?>

        <div class="col-lg-4 col-md-6 col-sm-12 col-12 mx-auto text-center">

            <div class="pricing-card<?php ee($plan['featured'] ? ' featured' : ''); ?>">

                <?php if (!empty($plan['badge'])): ?>
                    <span class="plan-badge"><?php ee($plan['badge']); ?></span>
                <?php endif; ?>

                <h3 class="plan-name"><?php ee($plan['name']); ?></h3>

                <hr>

                <div class="plan-price">
                    <sup>&#8377;</sup><span><?php ee($plan['price']); ?></span><sub>/mo</sub>
                </div>

                <p class="billed-text">Billed as &#8377;<?php ee($plan['billed']); ?> per year</p>

                <hr>

                <p class="unlock-title">Unlock Features :</p>

                <ul class="feature-list">
                    <?php foreach ($plan['features'] as $feature): ?>
                        <li><i class="fa fa-check" aria-hidden="true"></i> <?php ee($feature); ?></li>
                    <?php endforeach; ?>
                </ul>

                <div class="purchase-now">
                    <a href="<?php ee(url($plan_cta_url)); ?>" class="btn-purchase">Purchase Now</a>
                </div>

            </div>

        </div>

    <?php endforeach; ?>

</div>
