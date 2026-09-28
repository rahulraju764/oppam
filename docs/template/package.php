<?php
// MEMBER page.
// TODO(backend): once auth is real, guard this page:
//   if (!is_logged_in()) { header('Location: login.php'); exit; }
// Do NOT add the guard while the site is a static demo.
require_once __DIR__ . '/assets/includes/auth.php';

/* HEAD — everything else (canonical, og:url, og:image, twitter:*, robots
   gating, the CSS/JS links) comes from assets/includes/head.php. */
$page_title    = 'Membership Packages & FAQ | Oppam Matrimony';
$page_desc     = 'Upgrade your membership and unlock premium features, and find answers to common questions about registration, profiles and privacy.';
$page_keywords = 'Matrimony Membership Plans, Premium Matrimony Kerala, Matrimony Packages, Matrimony FAQ, Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';
$og_title      = 'Choose Your Membership Plan';
$og_desc       = 'Enjoy premium benefits and connect with more compatible profiles.';
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

	<!-- /*================ PACKAGE + FAQ PAGE ================*/ -->
	<!-- faq.php used to be a separate page; it now redirects here. The plans and the
	     questions people ask about them belong on one page. -->

	<!-- page hero -->
	<section class="page-hero">
		<div class="container is-chrome">
			<div class="page-hero-inner">
				<p class="eyebrow">Membership &amp; Help</p>
				<h1>Plans, pricing and answers</h1>
				<p>Everything you need to decide: what each membership unlocks, what it costs, and answers
					to the questions we get asked most about profiles, privacy and matches.</p>
				<div class="page-hero-actions">
					<a href="#plans" class="page-hero-btn">View plans</a>
					<a href="#faq" class="page-hero-btn is-ghost">Read the FAQs</a>
				</div>
			</div>
		</div>
	</section>
	<!-- page hero -->

	<!-- membership plans -->
	<!-- TODO(backend): plan names, prices and feature lists below are hardcoded demo data.
	     The "Purchase Now" links have no handler yet — point them at checkout once it exists. -->
	<section class="subscription-section" id="plans">
		<div class="container">
			<div class="row">
				<div class="col-lg-12 col-md-12 col-sm-12 col-12">
					<div class="subscription-content">
						<p class="eyebrow">Membership Plans</p>
						<h2>Choose the plan that suits you</h2>
						<p>Upgrade to view verified mobile numbers, chat directly with families, and get your profile
							shown to more matches across Kerala.</p>
					</div>
				</div>
			</div>

			<?php
			/* checkout.php reviews the plan; it takes no payment yet and says so on
			   the page. TODO(backend): pass the chosen plan — 'checkout.php?plan=' .
			   $plan['key'] — and select it there from the server's own plan table,
			   never from a price submitted by the client. */
			$plan_cta_url = 'checkout.php';
			?>
			<?php include 'assets/includes/pricing-cards.php'; ?>
		</div>
	</section>
	<!-- membership plans -->

	<!-- faq -->
	<section class="faq-section py-5" id="faq">
		<div class="container is-readable">

			<div class="faq-heading text-center">
				<span class="faq-tag">FAQ</span>
				<h2>Frequently Asked Questions</h2>
				<p>
					Have questions about creating your profile, searching for matches, or premium memberships?
					Explore our FAQs to get quick and helpful answers.
				</p>
			</div>

			<div class="row mt-4">

				<!-- Left Column -->
				<div class="col-lg-6 col-md-12 col-sm-12 col-12">
					<div class="accordion custom-accordion" id="faqLeft">

						<div class="accordion-item">
							<h3 class="accordion-header">
								<button class="accordion-button" type="button"
									data-bs-toggle="collapse" data-bs-target="#leftOne">
									How do I create a matrimony profile?
								</button>
							</h3>
							<div id="leftOne" class="accordion-collapse collapse show" data-bs-parent="#faqLeft">
								<div class="accordion-body">
									Create your profile for free by adding your details, preferences, and photos.
									Start connecting with suitable matches today.
								</div>
							</div>
						</div>

						<div class="accordion-item">
							<h3 class="accordion-header">
								<button class="accordion-button collapsed" type="button"
									data-bs-toggle="collapse" data-bs-target="#leftTwo">
									How do I verify my profile?
								</button>
							</h3>
							<div id="leftTwo" class="accordion-collapse collapse" data-bs-parent="#faqLeft">
								<div class="accordion-body">
									You can verify your profile by submitting the required identity documents
									and contact details. Verified profiles help build trust and improve match
									quality.
								</div>
							</div>
						</div>

						<div class="accordion-item">
							<h3 class="accordion-header">
								<button class="accordion-button collapsed" type="button"
									data-bs-toggle="collapse" data-bs-target="#leftThree">
									How can I search for suitable matches?
								</button>
							</h3>
							<div id="leftThree" class="accordion-collapse collapse" data-bs-parent="#faqLeft">
								<div class="accordion-body">
									Use our advanced search filters to find matches based on age, religion,
									community, education, profession, location, and other preferences.
								</div>
							</div>
						</div>

					</div>
				</div>

				<!-- Right Column -->
				<div class="col-lg-6 col-md-12 col-sm-12 col-12">
					<div class="accordion custom-accordion" id="faqRight">

						<div class="accordion-item">
							<h3 class="accordion-header">
								<button class="accordion-button" type="button"
									data-bs-toggle="collapse" data-bs-target="#rightOne">
									Can I update my profile after registration?
								</button>
							</h3>
							<div id="rightOne" class="accordion-collapse collapse show" data-bs-parent="#faqRight">
								<div class="accordion-body">
									Yes, you can update your profile details, photos, preferences, and contact
									information at any time through your account dashboard.
								</div>
							</div>
						</div>

						<div class="accordion-item">
							<h3 class="accordion-header">
								<button class="accordion-button collapsed" type="button"
									data-bs-toggle="collapse" data-bs-target="#rightTwo">
									Is my personal information secure?
								</button>
							</h3>
							<div id="rightTwo" class="accordion-collapse collapse" data-bs-parent="#faqRight">
								<div class="accordion-body">
									Yes, we prioritize your privacy and security. Your personal information
									is protected, and you have full control over who can view your profile
									and contact details.
								</div>
							</div>
						</div>

						<div class="accordion-item">
							<h3 class="accordion-header">
								<button class="accordion-button collapsed" type="button"
									data-bs-toggle="collapse" data-bs-target="#rightThree">
									How can I contact a matched profile?
								</button>
							</h3>
							<div id="rightThree" class="accordion-collapse collapse" data-bs-parent="#faqRight">
								<div class="accordion-body">
									Once you find a suitable match, you can send an interest request or
									communicate directly through our secure messaging system, depending on
									your membership plan.
								</div>
							</div>
						</div>

					</div>
				</div>

			</div>

		</div>
	</section>
	<!-- faq -->

    </main>

	<?php include_once('assets/includes/footer.php') ?>
	<?php include_once('assets/includes/footer2.php') ?>
	<?php include_once('assets/includes/script.php') ?>
</body>

</html>
