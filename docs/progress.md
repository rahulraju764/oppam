# Build Progress

Tick items as they are finished (tests green, quality gate passed, tried in the browser,
committed). Session details and prompts: `docs/build-prompts.md`. Spec:
`docs/Oppam_Matrimony_PRD_v5_Laravel_Livewire_Realtime.md`.

**Current phase:** 2 — Discovery (P2.2–P2.4 reopened after review, 2026-10-04)
**Next session:** P2.3 — Dashboard & my matches fix (M05)

## Before starting (outside tasks — start early)
- [ ] MSG91 account + DLT sender ID + OTP template approved
- [ ] Razorpay account (test keys now, live after KYC)
- [ ] Domain, mail provider (SES/Mailgun), S3 buckets (public + private), server (India region)
- [ ] Open decisions answered (PRD §19 and §11A B.24) → recorded in docs/decisions.md
- [ ] Terms & privacy text sent for legal review

## Phase 0 — Foundation
- [x] P0.1 Project bootstrap — 2026-09-27 (Windows + XAMPP; see decisions.md)
- [x] P0.2 Template assets & layouts — 2026-09-28 (Swiper 12, self-hosted fonts, Dusk suite; see decisions.md)
- [x] P0.3 Blade components & static public pages — 2026-09-28 (public pages = Livewire components; DemoContent until P8.1)
- [x] P0.4 Core schema, masters & seed data — 2026-09-28 (reviewer READY; commit held for owner review of users/otp_challenges schema — rule 5)
- [x] P0.5 Admin auth, RBAC & audit (A01 + A12 core) — 2026-09-28 (reviewer READY after 1 Blocker + 3 Majors fixed; commit held for owner review — rule 5)
- [x] P0.6 Settings, feature flags & entitlements service — 2026-09-28 (reviewer READY after 3 Majors fixed incl. a reproduced consume deadlock; commit held with P0.4/P0.5 for owner review — rule 5)

## Phase 1 — Identity & profiles
- [x] P1.1 Registration, OTP, login (M01) — 2026-09-29 (reviewer READY after 1 Blocker + 4 Majors fixed over 4 rounds; commit held for owner review — rule 5)
- [x] P1.2 Wizard steps 1–3 (M02) — 2026-10-01 (reviewer READY after 2 Majors fixed: missing-profile redirect loop, search-district decision recorded)
- [x] P1.3 Wizard steps 4–6, completeness, submit (M02) — 2026-10-01 (reviewer READY after 1 Major fixed: hobbies text blocked submit)
- [x] P1.4 Photos & media privacy (M11) — 2026-10-01 (reviewer READY after 1 Blocker fixed: clear photo derivable from blurred URL → HMAC conversion names; media ULID keys)
- [x] P1.5 Profile view, own & others (M03) — 2026-10-01 (reviewer READY after 1 Major fixed: an earlier contact reveal outlived HIDDEN / contact filter)
- [x] P1.6 Moderation queues (A04) — 2026-10-02 (reviewer READY in round 2 after 1 Major fixed: an edit approval could cover text the moderator never saw → fingerprint of the shown text)
- [x] P1.7a Member management (A03) — 2026-10-03 (reviewer READY in round 2 after 4 Majors fixed: broker ids in bulk, export N+1, purge left OTP/session rows, plan grant on an unverified number; owner accepted the session / plan side-effects)
- [x] P1.7b Impersonation, admin password reset, resend OTP (A01/A03) — 2026-10-04 (reviewer READY in round 3 after 5 + 1 Majors fixed; owner reviewed and approved the commit — rule 5)
- [x] P1.8 Master data management (A11) — 2026-10-04 (reviewer READY in round 2 after 1 Blocker + 1 Major fixed: districts editor (states as parents), diet preference usage)

## Phase 2 — Discovery
- [x] P2.1 Search service & search page (M04) — 2026-10-04 (reviewer READY in round 3 after 2 Majors + 1 Major fixed: bench cache isolation, privacy index, desktop filter rail; p95 323 ms at 100k with a sized buffer pool)
- [x] P2.1b Search follow-ups — 2026-10-04 (reviewer READY in round 1; Prev / Next from search results via ProfileBrowseList in Search & AllProfiles, profile-view rate limit profile_views.max_per_minute, A15)
- [x] P2.2 All profiles & saved searches (M04) — 2026-10-04 (reopened after an unreviewed first pass; reviewer READY in round 1: normalised saved filters, token unsubscribe with GET confirm + RFC 8058 POST, owner-only management, /profiles, phone sheet, Dusk desktop + 375px)
- [ ] P2.3 Dashboard & my matches (M05) — **reopened 2026-10-04**: first pass (b3ef47e) not reviewed; All = mutual fit + Mutual tab, counts per render, dashboard from daily_matches + #[Lazy], `/matches` route, a11y / inline styles, Dusk
- [ ] P2.4 Daily matches job & visitors (M05, M15) — **reopened 2026-10-04**: first pass (21f8c71) not reviewed; Visitors shows blocked / suspended members (Blocker), daily job repeats yesterday's batch and scores the first 200 by id, generation inside render(), counts rows not visitors, no "ready" email, approve → generate (P1.6 carry-over)

## Phase 3 — Real-time core & engagement
- [ ] P3.1 Reverb, Echo, channels & presence
- [ ] P3.2 Notifications system (M08)
- [ ] P3.3 Likes & favorites (M06)
- [ ] P3.4 Interests & requests (M06)
- [ ] P3.5 Live admin dashboard (A02)

## Phase 4 — Chat
- [ ] P4.1 Chat core: inbox, thread, send, receipts (M07)
- [ ] P4.2 Chat part 2: typing, history, extras (M07)
- [ ] P4.3 Content safety & chat moderation (A13)

## Phase 5 — Money
- [ ] P5.1 Plans, checkout & Razorpay (M10)
- [ ] P5.2 Entitlement sweep, billing history & expiry
- [ ] P5.3 Admin billing (A06)

## Phase 6 — Trust & support
- [ ] P6.1 ID verification (M09 + A05)
- [ ] P6.2 Block, ignore, report, safety centre, support desk (M09 + A10)

## Phase 7 — Broker module (PRD §11A)
- [ ] P7.1 Broker foundation, application & KYC
- [ ] P7.2 Referral program & materials
- [ ] P7.3 Managed profiles: create, manage, assign, notes
- [ ] P7.4 Client inbox, claim flow & exports
- [ ] P7.5 Staff accounts
- [ ] P7.6 Bulk upload part 1: template, upload, validation, preview
- [ ] P7.7 Bulk upload part 2: fix, consent, import, submit, safeguards
- [ ] P7.8 Payouts & broker admin screens (A07)
- [ ] P7.9 Broker security sweep

## Phase 8 — Content, settings & communication
- [ ] P8.1 CMS & public pages (M12 + A08)
- [ ] P8.2 Settings & privacy, success-story submission (M14 + M16)
- [ ] P8.3 Notifications, campaigns & journeys (A14)
- [ ] P8.4 Settings & configuration (A15)

## Phase 9 — Hardening & launch
- [ ] P9.1 Reports & analytics (A09)
- [ ] P9.2 Audit viewer & system health (A12)
- [ ] P9.3 Font Awesome 6, accessibility & performance pass
- [ ] P9.4 Security review & load test
- [ ] P9.5 Deployment
- [ ] Launch: APP_INDEXABLE=true (unless seo.indexable was set in A15 settings), live Razorpay keys, monitoring green

## Notes / deviations
<!-- Record anything built differently from the PRD, with the reason and a link to docs/decisions.md -->
- P1.4 local setup: run `php artisan storage:link` once (public disk for photo conversions). Demo photos are added by
  DemoProfilesSeeder only on a fresh seed (`php artisan migrate:fresh --seed`, LOCAL ONLY).
- P1.4 → P1.5: profile pages show photos only through `PhotoUrls::forViewer()`; horoscope links via `HoroscopeAccess::link()`.
- P1.4 → P1.6: photo queue uses `moderation_items` PHOTO rows (subject_id = media uuid) and `media.phash` for duplicates.
- P1.4 → P2.x: `PhotoUrls` queries per profile; add a batch `forViewers()` (eager-loaded) before search/list cards use it.
- P1.4 → P9.5: S3 public bucket with listing disabled; `media-library:regenerate` after an APP_KEY rotation (conversion names are keyed).
- P1.7b → M14 (password / email change), P5 (checkout, payments), P3.3/P3.4 (likes, favourites, interests), P4 (messages):
  each such Action calls `Impersonation::assertAllowed()` (blocked while an admin is impersonating — decisions 2026-10-03).
- P1.7b → M14: once emails are verified, members start receiving the impersonation / password-reset / suspension emails (verified-only by design).
- P1.7b → P3.1: ForceLogout broadcast when an impersonation ends; channel callbacks refuse an ended impersonation.
- P1.7b → any future Ban / member self-delete / "change password" action: end sessions through `ChangesMemberState::revokeSessions()` (epoch + remember token) and test it — the member-session middleware and "Log out" no longer rotate the remember token (decisions 2026-10-03).
- P1.7b → every later module: a side effect in a GET / `mount()` (e.g. mark-as-read) is not in the impersonation action audit — call
  `Impersonation::assertAllowed()` or audit it there. P3.1: consider leaving `/broadcasting/auth` POSTs out of `impersonation.action`.
- P1.7b → P9.5: production must keep oppam.in and admin.oppam.in on one registrable domain (the SameSite=Strict admin cookie).
- P1.7a → every later module that stores member data (likes, favourites, interests, messages, reports, verification
  documents, broker links…): extend `PurgeDeletedMember` to wipe or anonymise it, and add a test.
- P1.7a → P3.1: live `ForceLogout` on suspend / delete (today: signed out on the next request); P4: freeze conversations.
- P1.7a → P3.2: in-app + live copies of AccountSuspended / AccountReactivated / AdminMessage (mail only now).
- P1.7a → P5.2: expiry sweep clears `profiles.is_premium` when a complimentary (or paid) plan ends; P5.3: Orders tab + refunds.
- P1.7a → P3.3/P3.4, P4, P6, P7: Engagement, Conversations, Verification, Reports tabs; open-reports, broker and owner-type facets.
- P1.7a → P5.1: A03 search by order number; P6.1: A03 "force re-verification" quick action (needs the verification module).
- P1.7a → P9.4: member phone search uses a leading-wildcard LIKE — fine at launch scale, revisit with the load test.
- P2.1 → P9.5: size the production InnoDB buffer pool to hold the profiles + search tables (search p95 depends on it); re-run
  `profiles:search-benchmark` on staging. P3.4: add the "already sent interest" exclusion and disable Send Interest for
  profiles whose contact filter excludes the searcher (PreferenceMatcher::meetsAll). P6.2: Ignore / Unignore buttons (table exists).
- P1.8 → P9.5 (deploy): run `php artisan db:seed --class=MastersSeeder` once on production to add the profanity word lists
  (the seeder only adds missing codes; it never overwrites labels edited in A11 — but it does re-add a seeded row that
  was deleted in A11, so deactivate seeded rows rather than deleting them).
- P1.8 → P6.2 / P3.4: report reasons and icebreaker templates become A11 lists with their modules; P5: income-band amounts editor if needed.
- P1.6 → P3.2: moderation outcomes are mail-only (ProfileApproved / ProfileNeedsChanges / ProfileContentRejected); add in-app + live channels.
- P1.6 → P3.5: admin sidebar badges listen to `admin.queues` (AdminQueueCountChanged); moderator quality metrics with reports (P9.1).
- P1.6 → P2.4: match generation when a profile is approved (A04 "approve → match generation").
- P1.6 → P3.5: live "being reviewed by …" broadcast on claim; income/occupation-mismatch pre-flag (A04) later with matching data.
- P1.6 → P1.7: deleting / suspending a member closes their open moderation items.
- P1.6 review Minors carried into P1.7a: photo grid keeps A/D marks when the server refuses a batch; EditedFieldsQueue toasts an AuthorizationException; DecidePhoto re-reads the photo row locked. Later: an `escalated_at` column (Escalations shows updated_at).
- P1.6 → P2.x: move the upload-time duplicate-photo scan (UploadProfilePhoto → ModerationFlags::forPhoto) to a job on the `media` queue as the media table grows.
- P1.6 → P7: broker provenance flags ("Added by broker BRK…", import batch filter) on the A04 screens.
- P1.5 → P2.x: list pages call `ProfileBrowseList::remember(codes)` for Prev/Next; use `BlockList::hiddenFrom()` in search.
- P1.5 → P2.1: rate-limit profile-view recording with search; batch the similar-profile cards (one media + block query).
- P1.5 → P7: a managing broker may see its managed profiles' photos (R-M03-3) — PhotoUrls currently returns [] to non-members.
- P1.5 → P3.x: Like / Interest / Chat buttons on the profile page are disabled placeholders; name masking and
  ACCEPTED_ONLY contact / photo rules unlock with accepted interests (P3.4); ProfileViewed listener in P3.1.
- P1.4 → P3.4: `PhotoAccess::isConnected()` / `HoroscopeAccess` ON_REQUEST + ACCEPTED_ONLY plug into accepted interests.
- P1.3 → P1.5: R-M02-4 edited-fields queue (moderation_items PROFILE_EDIT) with the own-profile edit screen.
- P1.3 → P3.1: admin-domain /broadcasting/auth + Echo listener for `admin.queues` (channel + event already exist).
- P0.1: dev env is Windows + XAMPP (MariaDB, no Redis locally), Livewire 4 instead of 3, package routes
  locked down, public routes pinned to the app domain. All in docs/decisions.md (2026-09-27).
- Local URLs: http://localhost:8000 (site), http://admin.localhost:8000 (admin). Don't use 127.0.0.1.
- P0.3 → P1.1: replace the home hero form with `<livewire:public.quick-register>` in the same session that adds the
  `register` route (the hero form's submit is disabled only while that route is missing).
- P0.3 → P0.4: replace `DemoContent::plans()` with plans from the `plans` / `plan_features` tables.
- P0.3 → P9.5: nginx needs the `/index.php` → `/` 301 (Apache has it in public/.htaccess).
- P1.1 → P9.5: configure `trustProxies` for the load balancer / Cloudflare, otherwise every per-IP limit
  (OTP 200/day, 20 registrations/hour, 30 logins/min) becomes one site-wide limit. Add a scheduled clean-up
  of abandoned unverified registrations (P1.7 or later). Production must run nginx + PHP-FPM: the OTP SMS is sent
  after the response with defer(), which only hides the SMS time where the response is flushed first
  (not Apache mod_php / artisan serve). Register the MSG91 "account exists" DLT template (MSG91_ACCOUNT_EXISTS_TEMPLATE_ID).
- P0.5 → P1.7: admin impersonation (A01) is built with member management.
- Local admin sign-in: http://admin.localhost:8000 — admin@oppam.test (and one demo admin per role) with the
  ADMIN_SEED_PASSWORD from your local .env; 2FA is enrolled at first sign-in. Production: `php artisan oppam:create-super-admin`.
