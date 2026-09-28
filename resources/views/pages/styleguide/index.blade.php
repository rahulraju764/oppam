{{--
    /styleguide — local environment only (routes/web.php). A living sample of the template's
    type, buttons, chips, cards and form controls on the real layout, for visual checks at
    320 / 375 / 768 / 1024 / 1440 px. Never linked from the site.
--}}
<x-layouts::public :seo="\App\Data\Content\SeoData::private('Styleguide | Oppam Matrimony')">
    <section class="contact-section">
        <div class="container is-readable">
            <div class="contact-head">
                <span class="eyebrow">Styleguide</span>
                <h1>Oppam UI styleguide</h1>
                <p class="text-muted-brand">Template classes and design tokens, rendered by the Laravel layouts. Member chrome: <a href="{{ route('styleguide.member') }}" wire:navigate>/styleguide/member</a>.</p>
            </div>

            <h2 class="h4">Type</h2>
            <p>Body copy in Manrope. <span class="script-accent">Script accent</span> is decorative only.</p>
            <p class="text-muted-brand">Muted caption text uses --color-gr-dark.</p>

            <h2 class="h4 mt-4">Buttons &amp; chips</h2>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <a href="{{ route('styleguide') }}" class="upgrade-btn">Upgrade Now</a>
                <a href="{{ route('styleguide') }}" class="view-btn">View All <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                <div class="contact-button m-0"><button type="button"><i class="fa fa-paper-plane" aria-hidden="true"></i> Send Message</button></div>
                <span class="chip">Free</span>
                <span class="chip">Gold</span>
            </div>

            <h2 class="h4 mt-4">Card surface</h2>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="dashboard-sidebar">
                        <h3 class="h5">Card shell</h3>
                        <p class="mb-0">--radius-card, --pad-card, --shadow-card.</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="all-matches">
                        <h3 class="h5">Brand-accent panel</h3>
                        <p class="mb-0">White text on --color-primary with the pattern wash.</p>
                    </div>
                </div>
            </div>

            <h2 class="h4 mt-4">Form controls</h2>
            <div class="matrimony-forms">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="mat-label" for="sg-name">Name</label>
                        <input type="text" class="form-control mat-text" id="sg-name" name="sg_name" placeholder="Full name">
                    </div>
                    <div class="col-md-6">
                        <label class="mat-label" for="sg-religion">Religion</label>
                        <select class="form-select mat-text" id="sg-religion" name="sg_religion">
                            <option>Hindu</option>
                            <option>Christian</option>
                            <option>Muslim</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="mat-label" for="sg-about">About</label>
                        <textarea class="form-control mat-text" id="sg-about" name="sg_about" rows="3" placeholder="A few lines"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layouts::public>
