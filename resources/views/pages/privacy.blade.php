{{--
    Privacy (template privacy.php). Placeholder legal copy, NOT yet reviewed by counsel: every
    .legal-fill span (entity, CIN, grievance officer, retention periods, processors) must be filled
    and the text legally reviewed before launch (docs/progress.md "Before starting").
    Kept in English: legal text is translated as a reviewed document, not string by string.
--}}
<x-layouts::public :seo="new \App\Data\Content\SeoData(title: 'Privacy Policy | Oppam Matrimony', description: 'How Oppam Matrimony collects, uses, shares and protects your personal data, and the rights you have over it.', keywords: 'Oppam Matrimony Privacy Policy, Matrimony Data Protection, DPDP Act')">
    {{-- The design shows no page title here; this gives the document a
         proper top-level heading without changing anything visually. --}}
    <h1 class="visually-hidden">Privacy Policy</h1>

    {{-- /*================ PRIVACY PAGE ================*/
         Shell shared with terms.php: .container is-readable, .privacy-* classes.
         Every <h3 class="privacy-shd"> carries an id so the policy can be
         deep-linked (grievance replies, the DPDP notice, support macros all
         need to point at a clause, not at "the privacy page"). --}}

    <section class="privacy-section">
        <div class="container is-readable">
            <div class="row">
                <div class="col-lg-12 col-md-12 col-sm-12 col-12">
                    <div class="row">
                        <div class="col-lg-8 col-md-8 col-sm-12 col-12">
                            <div class="privacy-head">
                                {{-- <h2>, not <h3>: the page's only <h1> is the hidden one
                                     above, so this is the first visible heading and an <h3>
                                     skipped a level. Matches terms.php exactly. --}}
                                <h2 class="t-head">Privacy Policy of Oppam Matrimony</h2>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-4 col-sm-12 col-12">
                            <div class="privacy-head">
                                {{-- TODO(backend): set to the date this policy is actually
                                     published. Do not leave a date in the past on live copy. --}}
                                <p>Last Updated: <span class="legal-fill">[DD Month YYYY]</span></p>
                            </div>
                        </div>
                    </div>
                    <hr>

                    <p class="p-intro">This Privacy Policy explains what personal data
                        <span class="legal-fill">[Legal Entity Name Pvt. Ltd.]</span>
                        (&ldquo;Oppam Matrimony&rdquo;, &ldquo;we&rdquo;, &ldquo;us&rdquo;) collects when you use
                        oppam matrimony&rsquo;s website and application (the &ldquo;Platform&rdquo;), why we
                        collect it, who we share it with, how long we keep it, how we protect it,
                        and the rights you have over it. It is issued as the notice required by
                        section 5 of the Digital Personal Data Protection Act, 2023 (&ldquo;DPDP
                        Act&rdquo;), and as the privacy policy required by Rule 4 of the Information
                        Technology (Reasonable Security Practices and Procedures and Sensitive
                        Personal Data or Information) Rules, 2011 (&ldquo;SPDI Rules&rdquo;) and Rule
                        3(1)(a) of the Information Technology (Intermediary Guidelines and Digital
                        Media Ethics Code) Rules, 2021.</p>

                    <p class="p-intro">A matrimonial service is unusual in one respect and you
                        should be clear about it before you register: <strong>the whole purpose of
                        the Platform is to show information about you to strangers.</strong> What
                        you put on your profile is seen by other members. This policy tells you
                        exactly which fields are published, which are never published, and which are
                        released only when you choose to release them. Your use of the Platform is
                        also governed by our <a href="{{ route('terms') }}" wire:navigate>Terms of Use</a>, which forms part of
                        the same agreement.</p>

                    <h3 class="privacy-shd" id="who-we-are">1. Who we are, and who is answerable</h3>
                    <p class="p-main">For the purposes of the DPDP Act we are the <strong>Data
                        Fiduciary</strong> — we decide why and how your personal data is processed.
                        You are the <strong>Data Principal</strong>.</p>
                    <p class="p-main"><strong>Data Fiduciary:</strong>
                        <span class="legal-fill">[Legal Entity Name Pvt. Ltd.]</span>,
                        CIN <span class="legal-fill">[CIN]</span>, registered office at
                        <span class="legal-fill">[Registered address, City, Kerala, PIN]</span>,
                        India.</p>
                    <p class="p-main"><strong>Person answerable to you (s.8(9) DPDP Act) / Data
                        Protection Officer:</strong> <span class="legal-fill">[Name]</span>,
                        <a href="mailto:privacy@example.com" class="legal-fill">privacy@[domain]</a>.</p>
                    <p class="p-main"><strong>Grievance Officer (Rule 3(2)(a), IT Rules 2021):</strong>
                        see <a href="#grievance">clause 14</a>, which publishes the name, address,
                        email and the timelines we are bound to.</p>

                    <h3 class="privacy-shd" id="eligibility">2. Age, and profiles created by family</h3>
                    <p class="p-main">The Platform is not for children. Under section 9 of the DPDP
                        Act a &ldquo;child&rdquo; is anyone under 18, and we do not knowingly process
                        a child&rsquo;s personal data, do not track or behaviourally monitor children,
                        and do not direct advertising at them. Separately, and because of what this
                        service is for, registration is restricted to persons who are of legal
                        marriageable age under the Prohibition of Child Marriage Act, 2006 —
                        <strong>21 years for a man and 18 years for a woman</strong> — and who are
                        legally free to marry.</p>
                    <p class="p-main">Indian families routinely create matrimonial profiles for a
                        son, daughter, sibling or ward. That is allowed, but on one condition that we
                        treat as absolute: <strong>the person the profile is about must know it
                        exists and must have agreed to it.</strong> If you create a profile for
                        someone else you confirm to us that you have their informed consent, that you
                        are authorised to give consent on their behalf and to agree to this policy
                        for them, and that you will pass on anything we send you about it. The person
                        the profile describes may exercise every right in <a href="#your-rights">clause
                        10</a> directly, including deletion, and we will act on their instruction over
                        yours. If we are told a profile was created without the subject&rsquo;s
                        knowledge, we will suspend it while we check.</p>

                    <h3 class="privacy-shd" id="what-we-collect">3. What personal data we collect</h3>
                    <p class="p-main"><strong>(a) Data you give us when you register and build your
                        profile.</strong> Name; gender; date of birth; mother tongue and languages;
                        marital status; physical status and any disability you choose to state;
                        height, weight and body type; eating, drinking and smoking habits; religion,
                        caste, sub-caste and gothram; horoscope details including birth time and
                        birth place, star (nakshatram), raasi, dosham/chevvai status; education and
                        qualifications; occupation, employer and annual income; city, district and
                        state; family details including parents&rsquo; occupation, family type,
                        family status, number and marital status of siblings; property and financial
                        details if you choose to enter them; your partner preferences on every one of
                        the above; your photographs; and the free-text &ldquo;about me&rdquo; you
                        write.</p>
                    <p class="p-main"><strong>(b) Contact and account data.</strong> Mobile number,
                        email address, postal address, password (stored only as a salted one-way
                        hash — we never hold it in a readable form and cannot tell you what it is),
                        and your account and membership status.</p>
                    <p class="p-main"><strong>(c) Verification data.</strong> Where you ask to be
                        verified, or where we need to check an account, we collect government
                        identity documents and the details on them — for example Aadhaar (masked),
                        PAN, passport, driving licence or voter ID — and where relevant income or
                        employment proof, educational certificates, and a selfie or short video for
                        liveness. See <a href="#verification-data">clause 6</a>, which restricts what
                        we do with these very tightly.</p>
                    <p class="p-main"><strong>(d) Activity data on the Platform.</strong> Profiles
                        you viewed, shortlisted, blocked or ignored; interests you sent, received,
                        accepted or declined; messages and chat content; contact details you released
                        or were released to you; searches you ran and filters you saved; and your
                        last-seen time.</p>
                    <p class="p-main"><strong>(e) Payment data.</strong> Plan purchased, amount, date,
                        invoice number, and the transaction reference and status returned by the
                        payment gateway. <strong>We do not collect, see or store your card number,
                        CVV, UPI PIN, net-banking credentials or any other payment credential</strong>
                        — those are entered on the gateway&rsquo;s own hosted page and stay with the
                        gateway and your bank.</p>
                    <p class="p-main"><strong>(f) Technical and device data.</strong> IP address,
                        browser and device type, operating system, device identifiers, referring page,
                        pages viewed and time on them, crash logs, and cookie identifiers. See
                        <a href="#cookies">clause 12</a>.</p>
                    <p class="p-main"><strong>(g) Support and grievance data.</strong> What you write
                        to us, call recordings where we tell you a call is recorded, and the record of
                        how a complaint was handled.</p>
                    <p class="p-main">Where a field is optional we mark it as optional. You may leave
                        it blank; some matching features will simply be less precise.</p>

                    <h3 class="privacy-shd" id="sensitive-data">4. Sensitive personal data — and what we will never ask for</h3>
                    <p class="p-main">Several of the fields above are &ldquo;sensitive personal data
                        or information&rdquo; under Rule 3 of the SPDI Rules, or are otherwise
                        sensitive in effect: <strong>religion, caste, health and physical status,
                        horoscope and biometric-adjacent verification data, financial information and
                        passwords.</strong> We collect them because caste, community, horoscope
                        compatibility and health disclosure are the criteria on which arranged
                        matches are actually made in India, and a matrimonial service that refused to
                        record them would not function. They are collected on your explicit consent,
                        for the matchmaking purpose only, and are held under the controls in
                        <a href="#security">clause 9</a>.</p>
                    <p class="p-main">We will <strong>never</strong> ask you, by email, SMS, WhatsApp
                        or phone, for your password, an OTP, your card number, your CVV, your UPI PIN,
                        or a payment to a personal account. Anyone who does is not us. Report it
                        through <a href="#grievance">clause 14</a>. We do not conduct any part of the
                        payment flow outside the Platform.</p>

                    <h3 class="privacy-shd" id="purposes">5. Why we process it, and on what lawful basis</h3>
                    <p class="p-main">Under the DPDP Act personal data may be processed for a lawful
                        purpose either on your consent or for certain &ldquo;legitimate uses&rdquo;.
                        Ours are:</p>
                    <div class="table-responsive">
                        <table class="table legal-table">
                            <caption class="visually-hidden">Purposes for which Oppam Matrimony processes personal data and the lawful basis for each</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Purpose</th>
                                    <th scope="col">Data used</th>
                                    <th scope="col">Basis</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Creating your account and displaying your profile to other members</td>
                                    <td>3(a), 3(b)</td>
                                    <td>Your consent (s.6 DPDP Act) / performance of the Terms of Use</td>
                                </tr>
                                <tr>
                                    <td>Matching, ranking and recommending profiles, and running your searches</td>
                                    <td>3(a), 3(d)</td>
                                    <td>Your consent</td>
                                </tr>
                                <tr>
                                    <td>Letting members express interest, chat, and release contact details</td>
                                    <td>3(b), 3(d)</td>
                                    <td>Your consent, given per action</td>
                                </tr>
                                <tr>
                                    <td>Verifying identity and screening for fake, duplicate or fraudulent profiles</td>
                                    <td>3(c), 3(f)</td>
                                    <td>Consent; and legitimate use — prevention and investigation of fraud (s.7(i))</td>
                                </tr>
                                <tr>
                                    <td>Taking payment, issuing invoices, GST and statutory accounting</td>
                                    <td>3(e)</td>
                                    <td>Performance of contract; compliance with law (s.7(b), s.7(c))</td>
                                </tr>
                                <tr>
                                    <td>Service messages — OTPs, interest and message alerts, plan expiry, security notices</td>
                                    <td>3(b), 3(d)</td>
                                    <td>Your consent / performance of contract</td>
                                </tr>
                                <tr>
                                    <td>Marketing about our own plans and features</td>
                                    <td>3(b)</td>
                                    <td>Your consent — withdrawable at any time, see clause 10</td>
                                </tr>
                                <tr>
                                    <td>Safety, moderation, handling complaints and abuse reports</td>
                                    <td>3(d), 3(g)</td>
                                    <td>Consent; legitimate use (s.7(i)); Rule 3, IT Rules 2021</td>
                                </tr>
                                <tr>
                                    <td>Responding to lawful orders from courts and authorised agencies</td>
                                    <td>Any</td>
                                    <td>Compliance with law (s.7(c)), IT Act s.69 / s.91 BNSS</td>
                                </tr>
                                <tr>
                                    <td>Security, debugging, capacity planning and aggregate analytics</td>
                                    <td>3(f)</td>
                                    <td>Consent; legitimate interest in operating the service securely</td>
                                </tr>
                                <tr>
                                    <td>Publishing a success story with your photograph</td>
                                    <td>Photos, names</td>
                                    <td><strong>Separate, specific, written consent from both partners.</strong> Never automatic, and withdrawable</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="p-main">We do not sell your personal data. We do not rent or trade
                        member lists. We do not use your profile content to serve you third-party
                        behavioural advertising, and we do not build advertising profiles about you
                        from your caste, religion, health or horoscope data.</p>

                    <h3 class="privacy-shd" id="visibility">6. What other members can see</h3>
                    <p class="p-main">This is the clause most people actually need. On the Platform:</p>
                    <p class="p-main"><strong>Published on your profile and visible to other logged-in
                        members:</strong> your profile ID, first name or display name as you set it,
                        age, height, marital status, religion, caste and sub-caste, mother tongue,
                        education, occupation, income band, city/district, family details you entered,
                        your written introduction, your partner preferences, and your photographs
                        subject to the photo setting you choose.</p>
                    <p class="p-main"><strong>Never published, at any membership tier:</strong> your
                        mobile number, email address, postal address, password, payment data, identity
                        documents and the numbers on them, your date of birth in full (we show age),
                        and the contents of your chats. Your <em>exact</em> date and time of birth are
                        used for horoscope matching and are shown only if you switch on horoscope
                        sharing.</p>
                    <p class="p-main"><strong>Released only when you release it:</strong> contact
                        details pass to another member when you accept their interest or expressly
                        share them. A paid plan buys the ability to <em>ask</em>; it does not buy
                        anyone the right to your number. You can withdraw sharing later, but
                        understand the limit of that — <strong>information a person has already seen
                        cannot be un-seen, and we cannot retrieve what they wrote down.</strong></p>
                    <p class="p-main">You control photo visibility (all members / only members you
                        accept / protected by password), you can hide your profile from search, and
                        you can block a member so that neither of you appears to the other.</p>

                    <h3 class="privacy-shd" id="verification-data">7. Identity documents — a narrower promise</h3>
                    <p class="p-main">Identity documents are held to a stricter standard than the rest
                        of your profile. We commit that documents you upload for verification are:
                        used <strong>only</strong> to confirm you are who you say you are; never shown
                        to another member; never used for marketing, matching or scoring; stored
                        encrypted and outside the public web root, accessible only to the small
                        verification team on a logged, need-to-know basis; and <strong>deleted or
                        irreversibly redacted once verification is decided</strong> — we retain only
                        the outcome (verified / not verified), the document type, the last four
                        characters, and the date. Where we must keep more for a fraud investigation or
                        because a law requires it, we keep only what that requires, for only that
                        long.</p>
                    <p class="p-main">Do not upload a full, unmasked Aadhaar. Mask the first eight
                        digits, as UIDAI advises. We do not require, and will not store, your full
                        Aadhaar number, and we do not use Aadhaar authentication or eKYC unless and
                        until we are lawfully entitled to and tell you separately.</p>

                    <h3 class="privacy-shd" id="sharing">8. Who we share personal data with</h3>
                    <p class="p-main">We disclose personal data only as set out here.</p>
                    <div class="table-responsive">
                        <table class="table legal-table">
                            <caption class="visually-hidden">Categories of recipient with whom Oppam Matrimony shares personal data</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Recipient</th>
                                    <th scope="col">What they get</th>
                                    <th scope="col">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Other members</td>
                                    <td>Only what clause 6 says is published or released</td>
                                    <td>The service itself</td>
                                </tr>
                                <tr>
                                    <td>Payment gateway — <span class="legal-fill">[name]</span></td>
                                    <td>Name, email, mobile, amount, order reference</td>
                                    <td>To take payment. RBI-regulated; card data never reaches us</td>
                                </tr>
                                <tr>
                                    <td>SMS / OTP and email providers — <span class="legal-fill">[names]</span></td>
                                    <td>Mobile number, email, message content</td>
                                    <td>To deliver OTPs and service alerts</td>
                                </tr>
                                <tr>
                                    <td>Cloud hosting and backup — <span class="legal-fill">[provider, region]</span></td>
                                    <td>Data at rest, encrypted</td>
                                    <td>To run the Platform</td>
                                </tr>
                                <tr>
                                    <td>Verification / anti-fraud vendors — <span class="legal-fill">[names]</span></td>
                                    <td>Document data, strictly per clause 7</td>
                                    <td>To confirm identity</td>
                                </tr>
                                <tr>
                                    <td>Professional advisers — auditors, lawyers</td>
                                    <td>The minimum necessary</td>
                                    <td>Audit, legal advice, defence of claims</td>
                                </tr>
                                <tr>
                                    <td>Courts, police and authorised government agencies</td>
                                    <td>What the order or notice requires</td>
                                    <td>Lawful order under the IT Act, BNSS or other law</td>
                                </tr>
                                <tr>
                                    <td>An acquirer, in a merger or transfer of business</td>
                                    <td>Member data as a business asset</td>
                                    <td>Only with notice to you, and only if they take on this policy</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="p-main">Every processor is engaged under a written contract that binds
                        them to process only on our instructions, to keep the data confidential, to
                        maintain reasonable security practices under s.43A of the IT Act, to return or
                        destroy the data at the end, and not to use it for their own purposes. Under
                        s.8(1) DPDP Act we remain answerable to you for what they do with it.</p>
                    <p class="p-main">Where we are served with a demand for your data, we check that
                        it is legally valid, disclose only what it actually requires, and — unless we
                        are prohibited from doing so, or telling you would defeat an investigation —
                        we tell you.</p>

                    <h3 class="privacy-shd" id="security">9. How we protect it</h3>
                    <p class="p-main">We maintain reasonable security safeguards under s.8(5) of the
                        DPDP Act and reasonable security practices and procedures under s.43A of the
                        IT Act, aligned to IS/ISO/IEC 27001 as the SPDI Rules contemplate. In
                        practice: TLS on every connection; encryption of sensitive fields and of
                        identity documents at rest; passwords stored only as salted hashes; identity
                        documents stored outside the web root and never served directly; role-based
                        access with least privilege; access logging and periodic access review;
                        environment separation, so live member data is not used in testing;
                        encrypted, access-controlled backups; vulnerability patching and periodic
                        security testing; and staff bound by confidentiality and trained on this
                        policy.</p>
                    <p class="p-main"><strong>Breach notification.</strong> If a personal data breach
                        occurs we will notify the Data Protection Board of India and every affected
                        Data Principal, in the form and within the time the DPDP Act and its Rules
                        require, and we will report to CERT-In within <strong>6 hours</strong> of
                        becoming aware of a reportable cyber incident, as CERT-In&rsquo;s directions
                        of 28 April 2022 require. Our notice to you will say what happened, what data
                        was involved, what we have done, and what you should do.</p>
                    <p class="p-main">No system is perfectly secure, and we do not claim otherwise.
                        Your own part matters: use a password you use nowhere else, never share an
                        OTP, and log out on shared devices.</p>

                    <h3 class="privacy-shd" id="retention">10. How long we keep it</h3>
                    <p class="p-main">Section 8(7) of the DPDP Act requires us to erase personal data
                        once the purpose is served and retention is no longer necessary for a legal
                        obligation. Our schedule:</p>
                    <div class="table-responsive">
                        <table class="table legal-table">
                            <caption class="visually-hidden">Retention periods by category of personal data</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Data</th>
                                    <th scope="col">Kept for</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Active profile and account data</td>
                                    <td>While your account is open</td>
                                </tr>
                                <tr>
                                    <td>After you delete your account</td>
                                    <td>Erased or irreversibly anonymised within <span class="legal-fill">[30]</span> days, subject to the rows below</td>
                                </tr>
                                <tr>
                                    <td>After you merely <em>hide</em> or deactivate your profile</td>
                                    <td>Retained but hidden, so you can return; auto-deleted if dormant for <span class="legal-fill">[24]</span> months, after notice to you</td>
                                </tr>
                                <tr>
                                    <td>Chats and interest history</td>
                                    <td>Deleted with the account, except where preserved for an open abuse complaint or investigation</td>
                                </tr>
                                <tr>
                                    <td>Identity documents</td>
                                    <td>Deleted or redacted on completion of verification — clause 7</td>
                                </tr>
                                <tr>
                                    <td>Invoices, payment records, GST and books of account</td>
                                    <td><strong>8 years</strong> — s.128 Companies Act, 2013; and as required under the CGST Act, 2017 and Income-tax Act, 1961</td>
                                </tr>
                                <tr>
                                    <td>Records we must keep as an intermediary</td>
                                    <td><strong>180 days</strong> after cancellation or withdrawal of registration — Rule 3(1)(g)&ndash;(h), IT Rules 2021, or longer if a court or agency requires</td>
                                </tr>
                                <tr>
                                    <td>Server, access and security logs</td>
                                    <td><strong>180 days</strong> — CERT-In directions of 28 April 2022</td>
                                </tr>
                                <tr>
                                    <td>Grievance records</td>
                                    <td><span class="legal-fill">[3]</span> years from closure</td>
                                </tr>
                                <tr>
                                    <td>A record that a banned account was banned</td>
                                    <td>Kept indefinitely, in minimal form, so a banned member cannot simply re-register</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="privacy-shd" id="your-rights">11. Your rights, and how to use them</h3>
                    <p class="p-main">As a Data Principal under Chapter III of the DPDP Act you have
                        the following rights, and we will not charge you for exercising them:</p>
                    <p class="p-main"><strong>Right to access (s.11).</strong> A summary of the
                        personal data we hold about you, what we are doing with it, and the identities
                        of the Data Fiduciaries and processors with whom we have shared it.</p>
                    <p class="p-main"><strong>Right to correction and erasure (s.12).</strong>
                        Correction of inaccurate data, completion of incomplete data, updating, and
                        erasure of data we no longer need — subject only to retention we are legally
                        obliged to maintain under <a href="#retention">clause 10</a>.</p>
                    <p class="p-main"><strong>Right to withdraw consent (s.6(4)&ndash;(6)).</strong>
                        As easily as you gave it. Withdrawal is not retrospective — it does not make
                        past processing unlawful — and if you withdraw the consent the service depends
                        on, we may have to close your account.</p>
                    <p class="p-main"><strong>Right to grievance redressal (s.13).</strong> To
                        complain to us first, through <a href="#grievance">clause 14</a>.</p>
                    <p class="p-main"><strong>Right to nominate (s.14).</strong> To nominate another
                        individual to exercise your rights if you die or become incapable of acting.
                        Given what this Platform is, that matters: it is how a family can have a
                        profile taken down. Write to the address in clause 14 to record a nominee.</p>
                    <p class="p-main"><strong>Right to complain to the regulator.</strong> If you are
                        not satisfied with our answer you may complain to the <strong>Data Protection
                        Board of India</strong> under s.13(3). You may also use the National Cyber
                        Crime Reporting Portal at <a href="https://cybercrime.gov.in" rel="noopener noreferrer nofollow" target="_blank">cybercrime.gov.in</a>,
                        or the consumer route in clause 15 of our <a href="{{ route('terms') }}" wire:navigate>Terms of Use</a>.</p>
                    <p class="p-main"><strong>How to make a request.</strong> Most of it is
                        self-service: edit your profile, change your privacy settings, or delete your
                        account from your account page. For anything else, write to the Grievance
                        Officer. We will respond <strong>within 15 days</strong> as a rule and in any
                        event within the period the applicable rules require. We will verify that the
                        request really comes from you before we act on it — for a matrimonial account
                        that verification protects you, so please expect it.</p>
                    <p class="p-main"><strong>Your duties (s.15 DPDP Act).</strong> The Act also puts
                        duties on you: do not impersonate another person when giving your data, do not
                        suppress material information where it is required, do not register a false or
                        frivolous grievance, and give only authentic information when you ask for
                        correction. Section 15 is enforceable against Data Principals, and on this
                        Platform a false profile is not a technicality — it is the harm the whole
                        service exists to prevent.</p>

                    <h3 class="privacy-shd" id="communications">12. Messages you will get from us</h3>
                    <p class="p-main"><strong>Service messages</strong> — OTPs, security alerts,
                        interest and message notifications, payment receipts, plan expiry, policy
                        changes — are part of the service and cannot be switched off while your
                        account is open, though you can narrow which activity alerts you receive.
                        <strong>Promotional messages</strong> are sent only with your consent and
                        every one carries a way to stop them. Our commercial SMS and calls follow the
                        TRAI Telecom Commercial Communications Customer Preference Regulations, 2018,
                        and we honour DND registration.</p>

                    <h3 class="privacy-shd" id="cookies">13. Cookies and similar technologies</h3>
                    <p class="p-main">We use <strong>strictly necessary</strong> cookies to keep you
                        logged in, keep your session secure and prevent CSRF — the Platform cannot
                        work without them. We use <strong>preference</strong> cookies to remember
                        settings such as language and saved filters, and <strong>analytics</strong>
                        cookies to understand aggregate usage. Analytics and any marketing cookies are
                        set only with your consent, which you can change at any time from the cookie
                        settings link in the footer. Blocking strictly necessary cookies will log you
                        out. Browser &ldquo;Do Not Track&rdquo; signals have no agreed meaning and we
                        do not rely on them; use the cookie settings instead.</p>

                    <h3 class="privacy-shd" id="transfers">14. Where your data is stored</h3>
                    <p class="p-main">Your personal data is stored on servers located in
                        <span class="legal-fill">[India — region]</span>. Section 16 of the DPDP Act
                        permits transfer of personal data outside India except to countries the
                        Central Government restricts by notification. Where a processor named in
                        clause 8 processes data outside India, we transfer only what that service
                        needs, under contractual protections at least equal to this policy, and we
                        will not transfer to a restricted territory. Certain data may be required to
                        be held in India by sector regulators — for example payment data under the RBI
                        direction on Storage of Payment System Data — and where that applies we
                        comply.</p>

                    <h3 class="privacy-shd" id="grievance">15. Grievance Officer and how to complain</h3>
                    <p class="p-main">In compliance with Rule 3(2)(a) of the IT (Intermediary
                        Guidelines and Digital Media Ethics Code) Rules, 2021, s.13 of the DPDP Act and
                        Rule 5(9) of the SPDI Rules, the name and contact details of our Grievance
                        Officer are:</p>
                    <p class="p-main">
                        <strong>Grievance Officer:</strong> <span class="legal-fill">[Full name]</span><br>
                        <strong>Designation:</strong> <span class="legal-fill">[Designation]</span><br>
                        <strong>Address:</strong> <span class="legal-fill">[Full postal address, City, Kerala, PIN]</span>, India<br>
                        <strong>Email:</strong> <a href="mailto:grievance@example.com" class="legal-fill">grievance@[domain]</a><br>
                        <strong>Phone:</strong> <span class="legal-fill">[+91 …]</span><br>
                        <strong>Hours:</strong> <span class="legal-fill">[Mon–Fri, 10:00–18:00 IST]</span>
                    </p>
                    <p class="p-main">The timelines we are bound to, and hold ourselves to:</p>
                    <div class="table-responsive">
                        <table class="table legal-table">
                            <caption class="visually-hidden">Grievance handling timelines</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Complaint</th>
                                    <th scope="col">We will</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Any grievance</td>
                                    <td>Acknowledge within <strong>24 hours</strong> and dispose of it within <strong>15 days</strong> — Rule 3(2)(a)</td>
                                </tr>
                                <tr>
                                    <td>Non-consensual intimate imagery, nudity, morphed images or impersonation of a person</td>
                                    <td>Remove or disable access within <strong>24 hours</strong> of a complaint by or on behalf of the person — Rule 3(2)(b)</td>
                                </tr>
                                <tr>
                                    <td>Content flagged by a court order or a government agency notification</td>
                                    <td>Act within <strong>36 hours</strong> — Rule 3(1)(d)</td>
                                </tr>
                                <tr>
                                    <td>Information sought by an authorised agency for verification of identity, or prevention/investigation of an offence</td>
                                    <td>Provide within <strong>72 hours</strong> — Rule 3(1)(j)</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="p-main">Tell us your profile ID, what happened, when, and attach
                        screenshots if you have them. If your complaint is about another member,
                        please also use the in-product Report button — it preserves the evidence
                        automatically.</p>

                    <h3 class="privacy-shd" id="changes">16. Changes to this policy</h3>
                    <p class="p-main">We may update this policy. The version on this page is always
                        the current one and the date at the top tells you when it changed. Where a
                        change materially affects how we use your personal data or the rights you
                        have, we will notify you — by email and by a notice on the Platform — before
                        it takes effect, and where the change requires fresh consent under the DPDP
                        Act, we will ask for it rather than assume it. We keep the previous versions
                        and will supply one on request.</p>

                    <h3 class="privacy-shd" id="law">17. Governing law</h3>
                    <p class="p-main">This policy is governed by the laws of India. Disputes are
                        subject to clause 20 of our <a href="{{ route('terms') }}" wire:navigate>Terms of Use</a>, which is where
                        jurisdiction and dispute resolution are set out. Nothing in this policy limits
                        any right you have under the DPDP Act, the IT Act or the Consumer Protection
                        Act, 2019 — where they conflict, those Acts prevail.</p>

                </div>
            </div>
        </div>
    </section>


</x-layouts::public>
