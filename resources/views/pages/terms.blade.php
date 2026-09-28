{{--
    Terms (template terms.php). Placeholder legal copy, NOT yet reviewed by counsel: every
    .legal-fill span (entity, CIN, grievance officer, retention periods, processors) must be filled
    and the text legally reviewed before launch (docs/progress.md "Before starting").
    Kept in English: legal text is translated as a reviewed document, not string by string.
--}}
<x-layouts::public :seo="new \App\Data\Content\SeoData(title: 'Terms of Use | Oppam Matrimony', description: 'The terms that govern your use of Oppam Matrimony: accounts, profiles, conduct, membership, payments and refunds.', keywords: 'Oppam Matrimony Terms, Matrimony Terms of Use Kerala')">
    {{-- No visible page title in this design, matching privacy.php — the document
         still needs exactly one <h1>. --}}
    <h1 class="visually-hidden">Terms of Use</h1>

    {{-- /*================ TERMS PAGE ================*/
         Same shell as privacy.php: .container is-readable, .privacy-* classes.
         Deliberately NOT a new set of classes — these two pages are the same
         document type and must not drift apart the way the profile rows did.
         Every clause heading carries an id: privacy.php cross-references clause
         15 and clause 20 by anchor, and support replies need to cite a clause. --}}

    <section class="privacy-section">
        <div class="container is-readable">
            <div class="row">
                <div class="col-lg-12 col-md-12 col-sm-12 col-12">

                    <div class="row">
                        <div class="col-lg-8 col-md-8 col-sm-12 col-12">
                            <div class="privacy-head">
                                <h2 class="t-head">Terms of Use of Oppam Matrimony</h2>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-4 col-sm-12 col-12">
                            <div class="privacy-head">
                                {{-- TODO(backend): the real publication date. --}}
                                <p>Last Updated: <span class="legal-fill">[DD Month YYYY]</span></p>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <p class="p-intro"><strong>Please read this carefully. It is a binding contract.</strong>
                        These Terms of Use (&ldquo;Terms&rdquo;) are an electronic record under the
                        Information Technology Act, 2000 and are published in accordance with Rule
                        3(1)(a) of the Information Technology (Intermediary Guidelines and Digital
                        Media Ethics Code) Rules, 2021. They do not require a physical or digital
                        signature. By registering, creating a profile, or using any part of the Oppam
                        Matrimony website or application (the &ldquo;Platform&rdquo;), you agree to
                        them. <strong>If you do not agree, do not use the Platform.</strong></p>

                    <p class="p-intro">Our <a href="{{ route('privacy') }}" wire:navigate>Privacy Policy</a> and the plan details on
                        the <a href="{{ route('plans') }}" wire:navigate>membership plans</a> page are incorporated into and form
                        part of these Terms.</p>

                    <h3 class="privacy-shd" id="about">1. Who you are contracting with</h3>
                    <p class="p-main">The Platform is operated by
                        <span class="legal-fill">[Legal Entity Name Pvt. Ltd.]</span>
                        (&ldquo;Oppam Matrimony&rdquo;, &ldquo;we&rdquo;, &ldquo;us&rdquo;), a company
                        incorporated in India, CIN <span class="legal-fill">[CIN]</span>,
                        GSTIN <span class="legal-fill">[GSTIN]</span>, registered office at
                        <span class="legal-fill">[Registered address, City, Kerala, PIN]</span>, India.
                        Contact details and our Grievance Officer are in
                        <a href="#grievance">clause 15</a>. This disclosure is made as required by
                        Rule 4(2) of the Consumer Protection (E-Commerce) Rules, 2020.</p>

                    <h3 class="privacy-shd" id="definitions">2. Definitions</h3>
                    <p class="p-main"><strong>&ldquo;Member&rdquo;</strong> — any person registered on
                        the Platform. <strong>&ldquo;Profile&rdquo;</strong> — the information a Member
                        publishes about the prospective bride or groom.
                        <strong>&ldquo;Content&rdquo;</strong> — anything a Member uploads, writes,
                        sends or publishes, including photographs, horoscopes and messages.
                        <strong>&ldquo;Services&rdquo;</strong> — the matchmaking, search, interest,
                        messaging and related features offered on the Platform, free or paid.
                        <strong>&ldquo;You&rdquo;</strong> — the Member, and where a Profile is created
                        for another person, both the person creating it and the person it describes.</p>

                    <h3 class="privacy-shd" id="eligibility">3. Who may use the Platform</h3>
                    <p class="p-main">You may register only if all of the following are true:</p>
                    <ul class="p-main legal-list">
                        <li>You are of legal marriageable age under the Prohibition of Child Marriage
                            Act, 2006 — <strong>21 years for a man, 18 years for a woman</strong>.</li>
                        <li>You are legally competent to contract under s.11 of the Indian Contract
                            Act, 1872.</li>
                        <li>You are <strong>legally free to marry</strong>: unmarried, or a widow or
                            widower, or divorced by a decree that has been granted — not merely
                            filed for. A person whose marriage subsists may not register. We take
                            this seriously because a marriage arranged in breach of s.5 of the Hindu
                            Marriage Act, 1955 or the corresponding provision of the law applicable
                            to you is void, and because bigamy is an offence.</li>
                        <li>You are seeking a lawful matrimonial alliance for yourself or for a
                            person on whose behalf you are authorised to act.</li>
                        <li>You have not previously been removed from the Platform by us.</li>
                    </ul>
                    <p class="p-main">One person, one Profile. Duplicate Profiles are removed without
                        refund.</p>

                    <h3 class="privacy-shd" id="account">4. Your account</h3>
                    <p class="p-main">You are responsible for everything done through your account.
                        Keep your password confidential, do not share your account, and tell us
                        immediately at <a href="#grievance">the address in clause 15</a> if you suspect
                        unauthorised use. We will never ask you for your password or an OTP. Your
                        registered mobile number and email are the addresses at which we serve notices
                        on you; keep them current.</p>

                    <h3 class="privacy-shd" id="for-others">5. Profiles created for a family member</h3>
                    <p class="p-main">If you create or manage a Profile for your son, daughter,
                        sibling, relative or ward, you represent and warrant that the person concerned
                        <strong>knows the Profile exists, has consented to it</strong>, meets the
                        eligibility in clause 3, and has authorised you to accept these Terms and our
                        Privacy Policy for them. That person may exercise every right over the Profile
                        directly, including having it deleted, and we will act on their instruction in
                        preference to yours. A Profile created without the subject&rsquo;s knowledge
                        is a breach of these Terms and, depending on what is published, may amount to
                        an offence under s.66C or s.66D of the IT Act.</p>

                    <h3 class="privacy-shd" id="verification">6. Verification — what it is, and what it is not</h3>
                    <p class="p-main">We screen Profiles before they go live and we may ask for
                        identity, income, education or employment documents. We may decline, suspend or
                        remove a Profile we cannot verify.</p>
                    <p class="p-main"><strong>A verification badge means only that a document matching
                        the stated identity was produced to us and appeared genuine on the checks we
                        ran.</strong> It is not a certification of a Member&rsquo;s character,
                        intentions, marital status, health, income, family circumstances, criminal
                        record or the truth of anything on their Profile. We do not conduct background
                        checks, do not verify horoscopes, and do not act as a marriage bureau,
                        guardian, advisor or agent for any Member. <strong>Make your own enquiries
                        before you commit to anything.</strong> See <a href="#safety">clause 17</a>.</p>

                    <h3 class="privacy-shd" id="conduct">7. Member conduct and prohibited content</h3>
                    <p class="p-main">You agree that the information on your Profile is true, accurate
                        and current, and that you will correct it when it changes — in particular your
                        marital status. You agree not to host, display, upload, publish, transmit or
                        share any Content, and not to use the Platform, in a way that:</p>
                    <ul class="p-main legal-list">
                        <li>belongs to another person and to which you do not have a right — including
                            another person&rsquo;s photographs;</li>
                        <li>is obscene, pornographic, paedophilic, invasive of another&rsquo;s privacy
                            including bodily privacy, insulting or harassing on the basis of gender,
                            racially or ethnically objectionable, or otherwise inconsistent with or
                            contrary to the laws of India;</li>
                        <li>is defamatory, libellous, threatening, or promotes enmity between groups on
                            grounds of religion, caste or community;</li>
                        <li>relates to or encourages money laundering, gambling, or is otherwise
                            unlawful;</li>
                        <li>is harmful to a child;</li>
                        <li>infringes any patent, trademark, copyright or other proprietary right;</li>
                        <li>deceives or misleads about the origin of a message, or knowingly
                            communicates information which is patently false or misleading, or
                            impersonates another person;</li>
                        <li>threatens the unity, integrity, defence, security or sovereignty of India,
                            friendly relations with foreign States, or public order, or incites any
                            cognizable offence, or prevents investigation of any offence, or insults
                            any foreign nation;</li>
                        <li>contains a software virus or any code designed to interrupt, destroy or
                            limit the functionality of any computer resource;</li>
                        <li>is patently false or untrue, published with the intent to mislead or harass
                            for financial gain or to cause injury.</li>
                    </ul>
                    <p class="p-main">That list is the prohibition in Rule 3(1)(b) of the IT Rules,
                        2021, and we are required to inform you of it at least once every year. In
                        addition, and specific to a matrimonial service, you must not:</p>
                    <ul class="p-main legal-list">
                        <li>solicit money, gifts, loans, investment or dowry from any Member.
                            <strong>Demanding or giving dowry is an offence under the Dowry
                                Prohibition Act, 1961.</strong> Any Profile that does it is removed
                            immediately and reported;</li>
                        <li>use the Platform to advertise, recruit, sell, promote another service, or
                            to run an escort, visa, immigration, job or investment scheme;</li>
                        <li>contact a Member who has declined your interest, blocked you, or asked you
                            to stop; or contact a Member&rsquo;s family or employer through details
                            obtained here for any purpose other than a matrimonial proposal;</li>
                        <li>copy, scrape, harvest, index, republish or resell Profile data, photographs
                            or contact details, by any means, manual or automated. Systematic
                            extraction is unauthorised access under s.43 of the IT Act and we will
                            pursue it;</li>
                        <li>disclose to any third party outside the Platform the contact details,
                            photographs or personal information of another Member;</li>
                        <li>circumvent, disable or test any security feature, or use bots, crawlers or
                            automated tools on the Platform.</li>
                    </ul>
                    <p class="p-main">You retain ownership of your Content. By posting it you grant us
                        a non-exclusive, royalty-free, worldwide licence to host, store, reproduce,
                        adapt for format, and display it on the Platform for the purpose of providing
                        the Services — and for no other purpose. That licence ends when you delete the
                        Content or your account, save for backups pending deletion under the retention
                        schedule in our <a href="{{ route('privacy') }}#retention" wire:navigate>Privacy Policy</a>.
                        <strong>We will not use your photograph in advertising or as a success story
                        without your separate written consent.</strong></p>

                    <h3 class="privacy-shd" id="contact-details">8. Contact details and other Members&rsquo; privacy</h3>
                    <p class="p-main">Contact details are never published on a Profile. They pass to
                        another Member only when you accept an interest or expressly share them. A paid
                        plan buys the ability to <em>request</em> contact — it does not buy a right to
                        any Member&rsquo;s number, and a Member is free to say no. Details you receive
                        are given to you for one purpose only, a matrimonial proposal with that
                        Member. Passing them to anyone else, or using them for marketing, is a breach
                        of these Terms and may be an offence under s.72A of the IT Act.</p>

                    <h3 class="privacy-shd" id="intermediary">9. Our role — we are an intermediary</h3>
                    <p class="p-main">The Platform is an intermediary within the meaning of s.2(1)(w)
                        of the IT Act and an e-commerce entity under the Consumer Protection
                        (E-Commerce) Rules, 2020. We provide a venue where Members publish their own
                        information and find each other. <strong>Content is created by Members, not by
                        us.</strong> We do not initiate a transmission, select its receiver, or modify
                        the information in it, and we claim the protection of s.79 of the IT Act.</p>
                    <p class="p-main">We do moderate: we screen Profiles, act on reports, and remove
                        Content that breaches clause 7. That moderation is a safety measure and does
                        not make us the author or publisher of Member Content, nor does it oblige us to
                        pre-screen everything. On receiving actual knowledge by way of a court order or
                        a notification from an appropriate government agency, we will remove or disable
                        access to the material within <strong>36 hours</strong>, as Rule 3(1)(d)
                        requires.</p>

                    <h3 class="privacy-shd" id="ip">10. Our intellectual property</h3>
                    <p class="p-main">The Platform, its design, software, database structure, text,
                        graphics, logos and the marks &ldquo;Oppam&rdquo; and &ldquo;Oppam
                        Matrimony&rdquo; are owned by or licensed to us and are protected under the
                        Copyright Act, 1957 and the Trade Marks Act, 1999. You get a limited, personal,
                        non-transferable, revocable licence to use the Platform for your own
                        matrimonial search. Nothing else is granted.</p>

                    <h3 class="privacy-shd" id="fees">11. Memberships, fees and taxes</h3>
                    <p class="p-main">Basic membership is free. Paid plans, what each includes and what
                        each costs are set out on the <a href="{{ route('plans') }}" wire:navigate>membership plans</a> page, and
                        <strong>the price, duration and inclusions displayed there at the moment you
                        pay are the ones that apply to you</strong> — a later change to the plans does
                        not alter a plan you have already bought. All prices are in Indian Rupees and,
                        unless the page says otherwise, are inclusive of GST; a tax invoice is issued
                        under the CGST Act, 2017.</p>
                    <p class="p-main"><strong>What you are paying for is access to features</strong> —
                        the ability to send more interests, view contact details, message directly, or
                        appear higher in search. <strong>You are not paying for a match, a fixed number
                        of responses, a meeting, an engagement or a marriage, and we do not promise
                        any of those.</strong> No employee or agent of ours is authorised to promise
                        you a match, and any such assurance is void and not binding on us.</p>
                    <p class="p-main">Payments are taken through an RBI-authorised payment gateway. We
                        never see or store your card, UPI or net-banking credentials. If a payment is
                        debited but your plan is not activated, tell us with the transaction reference
                        and we will reconcile it and either activate the plan or refund it in full.</p>

                    <h3 class="privacy-shd" id="renewal">12. Duration and renewal</h3>
                    <p class="p-main">A plan runs for the period shown at purchase and expires at the
                        end of it. <span class="legal-fill">[Plans do NOT auto-renew; you will be
                        reminded before expiry and must choose to buy again.]</span></p>
                    <p class="p-main"><em>TODO(backend): if auto-renewal or a standing instruction /
                        e-mandate is ever introduced, this clause must state: the amount and frequency,
                        that a pre-debit notification is sent at least 24 hours in advance as the RBI
                        e-mandate framework requires, and a one-click way to cancel. Silent
                        auto-renewal on a matrimonial subscription is an unfair trade practice.</em></p>

                    <h3 class="privacy-shd" id="refunds">13. Cancellation and refunds</h3>
                    <p class="p-main">You may close your Profile at any time from your account page.</p>
                    <p class="p-main"><strong>Cooling-off.</strong> If you cancel within
                        <span class="legal-fill">[7]</span> days of buying a plan
                        <strong>and</strong> you have not used any paid feature — that is, you have sent
                        no interest, viewed no contact detail and sent no direct message under the plan
                        — we will refund the fee in full. Where you have used part of the plan, we may
                        deduct the value of what was used and the payment gateway charge, and refund
                        the balance.</p>
                    <p class="p-main"><strong>Beyond that window,</strong> fees for a plan period are
                        not refundable, because what you bought was access and the access was given.
                        This does not apply, and you will be refunded on a pro-rata basis, where: we
                        withdraw the Service; we suspend or close your account for a reason that is not
                        your fault; a paid feature was materially unavailable for a sustained period;
                        or a refund is required by law or ordered by a competent forum.</p>
                    <p class="p-main"><strong>No refund is due</strong> where your account is closed
                        for breach of clause 7, for a false Profile, for concealing a subsisting
                        marriage, or for harassment of another Member.</p>
                    <p class="p-main">Approved refunds are made to the original payment instrument
                        within <span class="legal-fill">[7&ndash;14]</span> working days of approval;
                        the time your bank takes to post it is outside our control. Request a refund
                        through <a href="#grievance">clause 15</a>, quoting your profile ID and the
                        transaction reference. Nothing in this clause limits your rights under the
                        Consumer Protection Act, 2019.</p>

                    <h3 class="privacy-shd" id="termination">14. Suspension and closure</h3>
                    <p class="p-main">We may suspend or terminate a Profile that breaches these Terms,
                        that we cannot verify, that appears to be false or duplicated, that is the
                        subject of credible complaints from other Members, or where continuing to host
                        it would expose Members to harm or us to liability. Where we can, we will tell
                        you the reason and give you a chance to answer — <strong>unless the breach is
                        one where delay itself is the danger</strong>, such as impersonation, sexual
                        content, a dowry demand, a financial scam or a threat, in which case we act
                        first and inform you after. You may appeal any termination to the Grievance
                        Officer, and to the Grievance Appellate Committee under Rule 3A of the IT
                        Rules, 2021.</p>
                    <p class="p-main">On termination, clauses 7 (as to Content already shared), 10,
                        13, 15, 16, 18, 19, 20 and 22 survive.</p>

                    <h3 class="privacy-shd" id="grievance">15. Grievance redressal and your consumer rights</h3>
                    <p class="p-main">Complaints — about another Member, about Content, about a
                        payment, or about us — go to our Grievance Officer, appointed under Rule 3(2)(a)
                        of the IT Rules, 2021 and Rule 4(5) of the Consumer Protection (E-Commerce)
                        Rules, 2020:</p>
                    <p class="p-main">
                        <strong>Grievance Officer:</strong> <span class="legal-fill">[Full name]</span><br>
                        <strong>Address:</strong> <span class="legal-fill">[Full postal address, City, Kerala, PIN]</span>, India<br>
                        <strong>Email:</strong> <a href="mailto:grievance@example.com" class="legal-fill">grievance@[domain]</a><br>
                        <strong>Phone:</strong> <span class="legal-fill">[+91 …]</span><br>
                        <strong>Hours:</strong> <span class="legal-fill">[Mon–Fri, 10:00–18:00 IST]</span>
                    </p>
                    <p class="p-main">We acknowledge every grievance within <strong>24 hours</strong>
                        and dispose of it within <strong>15 days</strong>. Complaints about
                        non-consensual intimate imagery, morphed images or impersonation are actioned
                        within <strong>24 hours</strong>. The full timetable, including our obligations
                        to courts and agencies, is in <a href="{{ route('privacy') }}#grievance" wire:navigate>clause 15 of the
                        Privacy Policy</a>.</p>
                    <p class="p-main">If you are not satisfied, you may take the matter to the
                        <strong>Grievance Appellate Committee</strong> under Rule 3A of the IT Rules,
                        2021, to the National Consumer Helpline (1915) or the INGRAM portal, to the
                        appropriate <strong>Consumer Commission</strong> under the Consumer Protection
                        Act, 2019, or — for anything involving personal data — to the
                        <strong>Data Protection Board of India</strong>. Nothing in these Terms takes
                        away that right or requires you to arbitrate a consumer complaint.</p>

                    <h3 class="privacy-shd" id="disclaimer">16. What we do not promise</h3>
                    <p class="p-main">The Services are provided on an &ldquo;as is&rdquo; and &ldquo;as
                        available&rdquo; basis. To the fullest extent permitted by law, and without
                        limiting any right you have as a consumer:</p>
                    <ul class="p-main legal-list">
                        <li><strong>We do not promise a match, a response, a meeting, an engagement or
                            a marriage</strong>, within any period or at all.</li>
                        <li>We do not verify, and are not responsible for, the truth of anything a
                            Member says about their age, marital status, caste, religion, horoscope,
                            income, education, occupation, health, family or intentions.</li>
                        <li>We are not a party to any conversation, meeting, engagement, marriage,
                            financial arrangement or dispute between Members. Anything that happens
                            after an introduction is between the Members and their families.</li>
                        <li>We do not guarantee uninterrupted or error-free operation, and we may
                            change, suspend or withdraw a feature.</li>
                        <li>Astrological, horoscope and compatibility outputs are provided for
                            convenience on the data you enter. They are not advice and we make no claim
                            about their accuracy.</li>
                    </ul>

                    <h3 class="privacy-shd" id="safety">17. Your safety</h3>
                    <p class="p-main">Please treat this as part of the contract, not as a footnote.
                        Meet in a public place. Involve your family. Verify independently — identity,
                        employment, education, marital status and family background — before you commit
                        to anything. <strong>Never send money, gold, gift cards or documents to
                        anyone you have met here, for any reason and however convincing the story
                        is.</strong> Requests for money, for an emergency abroad, for visa or travel
                        costs, or for &ldquo;investment&rdquo; are the standard shape of matrimonial
                        fraud. Do not share your OTP, bank details or intimate photographs with
                        anyone. Report anything of that kind to us immediately under clause 15, and to
                        <a href="https://cybercrime.gov.in" rel="noopener noreferrer nofollow" target="_blank">cybercrime.gov.in</a>
                        or 1930.</p>

                    <h3 class="privacy-shd" id="liability">18. Limitation of liability</h3>
                    <p class="p-main">To the maximum extent permitted by Indian law, we are not liable
                        for indirect, incidental, special, punitive or consequential loss, for loss of
                        opportunity, for mental distress, or for any loss arising from the conduct,
                        statements or omissions of another Member — including any fraud, misrepresentation,
                        cheating, extortion or harm committed by a Member.</p>
                    <p class="p-main"><strong>Our total aggregate liability to you, for all claims taken
                        together, is limited to the total fees you actually paid us in the twelve (12)
                        months immediately preceding the event giving rise to the claim</strong>, or
                        <span class="legal-fill">[₹5,000]</span>, whichever is higher.</p>
                    <p class="p-main">Nothing in these Terms excludes or limits our liability for
                        fraud, for wilful misconduct, for death or personal injury caused by our
                        negligence, for a breach of our obligations as a Data Fiduciary under the DPDP
                        Act, 2023, or for anything else that cannot lawfully be excluded. A limitation
                        that a competent court or forum finds unfair under s.2(46) of the Consumer
                        Protection Act, 2019 is to be read down to the extent necessary, and the rest
                        of this clause stands.</p>

                    <h3 class="privacy-shd" id="indemnity">19. Indemnity</h3>
                    <p class="p-main">You will indemnify and hold us, our directors, officers and
                        employees harmless against any claim, demand, proceeding, loss, damage, fine or
                        reasonable legal cost arising out of: your Content; your breach of these Terms
                        or of any law; a false or misleading statement on your Profile; your
                        publication of a Profile for a person who did not consent; your disclosure or
                        misuse of another Member&rsquo;s personal data; or your conduct towards another
                        Member. We will notify you of any such claim and you may participate in the
                        defence at your cost; you will not settle it in a way that imposes an
                        obligation on us without our written consent.</p>

                    <h3 class="privacy-shd" id="law">20. Governing law, jurisdiction and dispute resolution</h3>
                    <p class="p-main">These Terms are governed by and construed in accordance with the
                        laws of India.</p>
                    <p class="p-main"><strong>Step 1 — talk to us.</strong> Raise the dispute with the
                        Grievance Officer under clause 15 first. Most matters end here.</p>
                    <p class="p-main"><strong>Step 2 — arbitration (commercial disputes only).</strong>
                        Any dispute that is not a consumer complaint, and that is not resolved within
                        <span class="legal-fill">[30]</span> days of being raised, shall be referred to
                        and finally resolved by arbitration by a sole arbitrator under the Arbitration
                        and Conciliation Act, 1996. The seat and venue of arbitration is
                        <span class="legal-fill">[Ernakulam, Kerala]</span>; the language is English;
                        the award is final and binding. Each side bears its own costs unless the
                        arbitrator directs otherwise.</p>
                    <p class="p-main"><strong>Step 3 — courts.</strong> Subject to the above, the courts
                        at <span class="legal-fill">[Ernakulam, Kerala]</span> have exclusive
                        jurisdiction.</p>
                    <p class="p-main"><strong>This clause does not take away your consumer rights.</strong>
                        Under s.34(2)(d) of the Consumer Protection Act, 2019 you may file a consumer
                        complaint where you reside or work, and nothing here obliges you to arbitrate
                        such a complaint or to travel to Kerala to bring one. Either side may also apply
                        to a court for urgent interim relief.</p>

                    <h3 class="privacy-shd" id="force-majeure">21. Force majeure</h3>
                    <p class="p-main">We are not liable for any failure or delay caused by an event
                        beyond our reasonable control — including act of God, flood, epidemic or
                        pandemic, war, riot, strike, fire, failure of power or telecommunications,
                        cyber-attack, a change in law, or an order of a government or court, including
                        an order to block or suspend a service.</p>

                    <h3 class="privacy-shd" id="general">22. General</h3>
                    <p class="p-main"><strong>Notices.</strong> We serve notices on you at your
                        registered email or mobile number, or by a notice on the Platform; you serve
                        notices on us at the address in clause 15.
                        <strong>Assignment.</strong> You may not assign these Terms; we may assign them
                        to a successor of our business on notice to you.
                        <strong>Severability.</strong> If any provision is held unenforceable, it is
                        severed and the remainder stands.
                        <strong>Waiver.</strong> A failure to enforce a provision is not a waiver of it.
                        <strong>Entire agreement.</strong> These Terms, the Privacy Policy and the plan
                        page are the whole agreement between us on this subject and supersede any
                        earlier assurance, oral or written.
                        <strong>Language.</strong> The English version of these Terms prevails over any
                        translation.
                        <strong>Relationship.</strong> Nothing here creates a partnership, agency,
                        employment or joint venture between us.</p>

                    <h3 class="privacy-shd" id="changes">23. Changes to these Terms</h3>
                    <p class="p-main">We may update these Terms. The version on this page is always the
                        current one and the date at the top tells you when it last changed. Where a
                        change is material — to fees, refunds, liability or dispute resolution — we will
                        give you notice by email and on the Platform before it takes effect, and if you
                        do not accept it you may close your account and, where you are inside a paid
                        plan period, receive a pro-rata refund of the unused part. Continuing to use the
                        Platform after a change takes effect means you accept it. We remind you of the
                        rules in clause 7 at least once a year, as Rule 3(1)(c) of the IT Rules, 2021
                        requires.</p>

                    <h3 class="privacy-shd" id="contact">24. Contact</h3>
                    <p class="p-main">Questions about these Terms go to our
                        <a href="{{ route('contact') }}" wire:navigate>contact page</a> or to the Grievance Officer named in
                        <a href="#grievance">clause 15</a>.</p>

                </div>
            </div>
        </div>
    </section>


</x-layouts::public>
