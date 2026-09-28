# Oppam Matrimony — AI Build Prompt Pack

Ready-to-paste prompts for building the whole product with Claude Code, one focused session at a
time. Session IDs (P0.1 …) match the checklist in `docs/progress.md`.

## How to use this pack

1. **One session = one prompt.** Start each with `/clear` so the context is fresh.
2. Paste the **Session Starter** (below) + the session's prompt.
3. Use **plan mode** (Shift+Tab) for the first message: let Claude plan, read the plan, correct it,
   then approve.
4. After the build: tests green → `oppam-reviewer` agent says **READY TO COMMIT** with a
   completion report → try it yourself in the browser → commit → tick the item in
   `docs/progress.md`. Sessions touching **login/OTP/2FA/sessions or payments** also need your own
   line-by-line review (they are on the "never allow unreviewed" list).
5. If a session runs long or goes off track: ask for a summary of what's done/not done, commit
   what works, `/clear`, and continue with the **Resume** prompt.

PRD = `docs/Oppam_Matrimony_PRD_v5_Laravel_Livewire_Realtime.md`. Section numbers below refer to it.

---

## Session Starter (paste before every session prompt)

```
Read CLAUDE.md and docs/progress.md. We are doing session <ID> from docs/build-prompts.md.
Read ONLY the PRD sections listed in that session (plus anything CLAUDE.md requires).
Load the project skills oppam-code-standards and oppam-testing, plus oppam-ui-standards if any
view/CSS/JS changes. Inspect the existing code before planning.
First give me a plan: files to create/change (migrations, models, enums, actions, Livewire
components, views, routes, notifications/events, tests) and any question where the PRD is
unclear. Do not write code until I approve the plan.
When building: follow the skills, write Pest tests for success paths and the security matrix
(name them with PRD rule ids), run the tests, fix failures, run pint and phpstan, self-check with
oppam-review-gate, then run the oppam-reviewer agent and give me the completion report in the
oppam-review-gate format. Update docs/progress.md. Do not commit unless I ask.
```

---

## Phase 0 — Foundation

### P0.1 — Project bootstrap
```
Read: PRD §4 Tech Stack, §5 Architecture (5.3, 5.4), §15 Environment.
Build:
- Install and configure: livewire/livewire 3, laravel/reverb, laravel/horizon, laravel/fortify,
  spatie/laravel-permission, spatie/laravel-medialibrary, maatwebsite/excel, barryvdh/laravel-dompdf,
  razorpay/razorpay, pestphp/pest (+ livewire plugin), larastan, laravel/pint, laravel/dusk (dev),
  laravel/pulse, sentry/sentry-laravel.
- Folder skeleton from §5.4 (Actions, Livewire/{Public,Member,Broker,Admin,Shared}, Services, Enums).
- routes/web.php, broker.php, admin.php (domain-routed via config('app.admin_domain')),
  channels.php, webhooks.php registered in bootstrap/app.php.
- .env.example exactly per §15 (Reverb + Pusher keys, Razorpay, MSG91, S3 two buckets).
- Timezone Asia/Kolkata display helper, Model::preventLazyLoading() in non-prod, strict mode.
- Horizon supervisors for queues: default, broadcasts, notifications, mail, media, matching, imports.
- phpstan.neon (level 6), pint.json, a GitHub Actions CI workflow running pint, phpstan, pest
  with MySQL + Redis services.
Done when: `php artisan about` is clean, `composer check` passes, CI file exists.
(P0.1 was completed on Windows + XAMPP — see docs/decisions.md.)
```

### P0.2 — Template assets & layouts
```
Read: PRD §6 (6.1), docs/template-notes.md (sections: shared includes, head, mobile drawer,
page-nav bar, preloader, containers, design tokens), docs/template/assets/includes/*.php.
Build:
- Move docs/template/assets/css/style.css + responsive.css into resources/css, custom.js into
  resources/js (split: preloader.js, drawer.js, carousels.js, pagenav.js, app.js), images into
  public/images. Load Bootstrap 5.3.2, Swiper 11 through npm + Vite (no CDN). Keep Font Awesome 4.7
  via npm for now.
- Layouts: layouts/public, layouts/member, layouts/broker, layouts/admin (admin: plain skeleton
  for now) with partials head, header (preloader + sticky nav + @auth branch), page-nav
  (config/pagenav.php keyed by route name), footer, mobile tab bar (member only).
- SEO head from $seo view data; robots noindex unless config('app.indexable').
- A /styleguide route (local env only) rendering buttons, cards, chips, forms from the template.
Done when: layouts render with identical look to the template at 360px, 768px, 1440px; no
horizontal overflow at 360px; preloader and mobile drawer work.
```

### P0.3 — Blade components & static public pages
```
Read: PRD §6.2, §10 M12, docs/template-notes.md (one component one file, page map),
docs/template/index.php, about.php, branches.php, success-stories.php, package.php, contact.php,
privacy.php, terms.php, 404.php, assets/includes/{profile-row,profile-tile,member-card,story-card,
pricing-cards,pagination,ads}.php.
Build:
- Blade components: x-profile-row, x-profile-tile, x-member-card, x-story-card, x-pricing-card,
  x-pagination (wraps Laravel paginator), x-ad-unit, x-page-nav, x-online-dot (placeholder).
- Convert the public pages to Blade views on named routes (/, /about, /branches, /success-stories,
  /plans, /contact, /privacy, /terms, 404 view). Data still static arrays in a temporary
  App\Support\DemoContent class (will become CMS tables in P8.1).
- 301 redirects for every legacy *.php URL (routes/legacy.php).
Done when: every public template page renders at its new route and matches the template;
feature tests assert 200 for each route and 404 status for an unknown URL.
```

### P0.4 — Core schema, masters & seed data
```
Read: PRD §7 (7.1, 7.2), §7.3, v4 appendix master seed lists are summarised in PRD §7.1
(if a seed list is missing, propose values and ask).
Build:
- Migrations + models + factories: users, otp_challenges, profiles, education_careers,
  family_details, partner_preferences, contact_details, horoscope_details, lifestyle_details,
  privacy_settings, notification_preferences, all master_* tables (castes with religion parent,
  districts with state parent).
- Enums: ProfileStatus, Gender, MaritalStatus, CreatedFor, PhotoVisibility, PhoneVisibility, etc.
- Masters service with tagged cache + observer flush.
- Seeders: masters (Kerala 14 districts, 27 stars, 12 rasi, religions + castes, education,
  occupations, income bands, mother tongues), plans (Silver/Gold/Diamond, prices in paise) +
  plan_features per §7.3, 200 demo profiles (use template demo names/images), settings defaults.
- Profile code generator (OPM + sequence) — never user-supplied.
Done when: migrate:fresh --seed works; tests for code generation, caste-by-religion scope,
age calculation.
```

### P0.5 — Admin auth, RBAC & audit (A01 + A12 core)
```
Read: PRD §8 (8.1, 8.4), §11.0, §11 A01, A12 (audit part only).
Build:
- admin_users table, `admin` guard + separate session cookie, login on admin domain.
- Fortify TOTP 2FA mandatory (enrol on first login, 10 recovery codes), lockouts
  (3 fails → 30 min), idle 30 min / absolute 12 h timeout.
- spatie roles/permissions for the admin guard; seed roles super_admin, moderator,
  verification_officer, support, finance, content_editor, read_only with keys from §8.4.
- AuditLogger::record() + append-only AuditLog model (throws on update/delete) + migration.
- Admin layout per §11.0 (sidebar with @can-filtered sections, top bar, toast stack) and
  x-admin.table / stat-card / confirm-modal / drawer components.
- Staff list, invite (72 h link), roles editor with guardrails (can't grant what you don't hold,
  last super_admin protected), session list/revoke.
Done when: an admin can't reach any admin page without 2FA; tests for lockout, guardrails, audit
immutability, permission-gated sidebar.
```

### P0.6 — Settings, feature flags & entitlements service
```
Read: PRD §7.3, §11 A15 (business rules + feature flags lists).
Build:
- settings (key/value, typed, cached) + feature_flags tables and Settings / Feature facades.
- EntitlementService: current plan for a profile, limit lookup from plan_features, usage counters
  (entitlement_usages) with daily (IST midnight) and monthly (anniversary) resets, `can()` and
  `consume()` methods, atomic (lockForUpdate) consumption.
- Seed every setting/flag named in A15 with the PRD default.
Done when: unit/feature tests cover free vs paid limits, reset boundaries, concurrent consume.
```

---

## Phase 1 — Identity & profiles

### P1.1 — Registration, OTP, login (M01)
```
Read: PRD §8.2, §10 M01, §6.2 (auth rows), docs/template/register.php, login.php,
forgot-password.php, index.php (hero form).
Build:
- SmsGateway interface + Msg91Gateway + LogSmsGateway (local) bound by env.
- Livewire: Public\QuickRegister (home hero), Member\Auth\Register, VerifyOtp (resend countdown),
  Login (OTP or password), ForgotPassword (OTP), logout + "log out other devices".
- Actions: RegisterMember, SendOtp, VerifyOtp, LoginWithOtp, LoginWithPassword, ResetPassword.
- OTP rules R-M01-2 (hashed, 5 min TTL, 3 attempts, send limits), non-enumeration R-M01-4,
  suspended users blocked R-M01-5, ?ref= cookie capture stored for later (broker resolve comes in P7.2).
- Middleware: verified.phone, profile.onboarded (redirects to /onboarding until submitted).
Done when: register → OTP → lands on /onboarding/1; all M01 acceptance criteria have tests.
```

### P1.2 — Profile wizard, steps 1–3 (M02)
```
Read: PRD §10 M02, docs/template/profile-creation.php, education.php, family.php.
Build:
- Member\Onboarding\Wizard full-page component with $step, one Form Object per step
  (BasicForm, CareerForm, FamilyForm), dependent selects (religion→caste, country→state→district),
  autosave on Next + debounced, progress bar, template markup kept.
- Actions: SaveWizardStep (per step), age rule (18 F / 21 M).
Done when: refresh mid-wizard restores data; caste list changes without reload; tests per rule.
```

### P1.3 — Wizard steps 4–6, completeness, submit (M02)
```
Read: PRD §10 M02 (rules R-M02-1..5), docs/template/partner.php, contact-details.php,
profile-photos.php (form part only — photos upload itself comes in P1.4, use a placeholder).
Build: PreferenceForm, ContactForm, AboutForm; CompletenessCalculator (weights in R-M02-3);
SubmitProfile action (DRAFT → PENDING_REVIEW, emits ProfileSubmitted event for admin queue);
rejected-profile banner + edit & resubmit; field locks after first publish (R-M02-1).
Done when: M02 acceptance tests pass.
```

### P1.4 — Photos & media privacy (M11)
```
Read: PRD §10 M11, §7.2 privacy_settings.
Build: medialibrary collections (photos, horoscope on private disk), conversions thumb/card/full/
blurred (queued on `media`), EXIF strip, perceptual hash, upload with progress (WithFileUploads),
crop via Cropper.js + Alpine, reorder, primary, caption, delete, max 10; photo status PENDING/
APPROVED/REJECTED; PhotoVisibility resolver used by every view; signed URLs for horoscope.
Wire into wizard step 6.
Done when: non-permitted viewers only ever receive the blurred URL (test); pending photos visible
to owner only.
```

### P1.5 — Profile view, own & others (M03)
```
Read: PRD §10 M03, docs/template/single-profile.php, my-profile.php.
Build: Member\Profile\Show (/profile/{code}) and MyProfile (/me): gallery, badges, tabs,
"you match X of Y preferences" checklist, masked contact + ViewContact action (entitlement +
visibility + contact filter + contact_views), similar profiles, prev/next from session result set,
RecordProfileView (daily upsert, skip incognito). Action bar placeholders for like/favorite/
interest (real in P3.x).
Done when: blocked pairs get 404 (test once blocks table exists — add the table now); contact
reveal consumes exactly once per pair; non-ACTIVE profiles hidden from others.
```

### P1.6 — Moderation queues (A04)
```
Read: PRD §11 A04.
Build: Admin\Moderation\ProfileQueue, ProfileReview, PhotoQueueGrid (keyboard A/D/arrows/Space/
Enter via Alpine), EditedFieldsQueue, Escalations; 15-min claim lock (Cache::lock); pre-flags
(contact info in text, profanity EN/ML list, underage auto-reject, duplicate pHash, duplicate
name+DOB); decisions approve/reject(category→member template)/request changes/escalate;
approval sets published_at + notifies member (simple mail for now; live in P3.2); audit every
decision.
Done when: A04 acceptance criteria tested; two moderators can't claim the same item.
```

### P1.7 — Member management (A03)
```
Read: PRD §11 A03.
Build: Admin\Members\Index (unified search, facets, bulk suspend/activate/notify with typed
reason, audited CSV export) and Admin\Members\Show (lazy tabs listed in A03); quick actions;
SuspendMember (revokes sessions, hides from search, pauses subscription placeholder);
two-stage deletion (soft → purge job); impersonation (30 min, banner, blocked routes, audit).
Done when: A03 rules tested, including no bulk delete and impersonation limits.
```

### P1.8 — Master data management (A11)
```
Read: PRD §11 A11.
Build: Admin\Masters\ListEditor for every master list (immutable code, editable label,
deactivate-not-delete when used, drag-sort, parent handling for castes/districts, usage_count),
CSV export + import with preview diff (plain controller), cache flush on write.
Done when: editing a caste label shows in the wizard within one request (cache flushed).
```

---

## Phase 2 — Discovery

### P2.1 — Search service & search page (M04)
```
Read: PRD §10 M04, §7.2 indexes, docs/template/search.php.
Build: App\Services\ProfileSearch (query builder, always-on exclusions: ACTIVE, opposite gender,
not blocked either way, not ignored, not incognito, contact-filter flag), relevance/newest/
last-active/verified sorts, cursor pagination. Member\Search\Search with every filter as #[Url]
property, debounced live updates, skeleton loading, result count, load-more via x-intersect,
mobile filter sheet, ID search box.
Done when: URL reproduces results; blocked/ignored never appear (tests); p95 < 800 ms on 100k
seeded profiles (add a `profiles:seed-bulk` command for perf testing).
```

### P2.2 — All profiles & saved searches (M04)
```
Read: PRD §10 M04 (saved searches), docs/template/all-profiles.php.
Build: Member\Browse\AllProfiles (3/6/3 layout, sort), SavedSearch CRUD (max 10), daily/weekly
SendSavedSearchAlerts job (only profiles published after last_alerted_at), unsubscribe link.
Done when: alert job tests pass.
```

### P2.3 — Dashboard & my matches (M05)
```
Read: PRD §10 M05, docs/template/dashboard.php, my-matches.php.
Build: MatchScorer (preference fit 60 / reverse fit 25 / activity 10 / completeness 5, cached in
match_scores), Member\Dashboard (sidebar, lazy sliders with Swiper re-init after Livewire
render, counters), Member\Matches\MyMatches tabs (All, New, Yet to view, Viewed, Mutual, Near me,
Premium) with 2×2 funnel counters.
Done when: scorer unit tests; dashboard renders with seeded data and no N+1 (test with
preventLazyLoading).
```

### P2.4 — Daily matches job & visitors (M05, M15)
```
Read: PRD §12 F06, §10 M05 daily matches, §10 M15, docs/template/daily-matches.php.
Build: GenerateDailyMatches (05:00 IST, chunked per 1,000 on `matching` queue, diversity cap,
exclusions, persist, expiry 23:59), countdown timer on dashboard, Member\Matches\Daily,
Member\Activity\Visitors (Gold+ full list, others count + blurred teaser), Profiles I viewed.
Done when: job test builds batches for seeded members by 06:00 IST equivalent; visitor
entitlement gating tested.
```

---

## Phase 3 — Real-time core & engagement

### P3.1 — Reverb, Echo, channels & presence
```
Read: PRD §9 entirely.
Build: Reverb config (env per §15), resources/js/echo.js exactly as §9.2 (driver switch
reverb|pusher), routes/channels.php for every channel in §9.3 with DB re-checks, presence.online
join in member layout + Alpine $store.online + x-online-dot, last_seen_at debounced writer,
ForceLogout event + listener in layout, reconnect hook that dispatches a Livewire 'realtime-
reconnected' event, polling fallback behind realtime.polling_fallback flag. Dusk base test
that boots Reverb.
Done when: channel auth tests (participant yes/no, blocked pair no); a Dusk test shows the online
dot turning green when a second browser logs in.
```

### P3.2 — Notifications system (M08)
```
Read: PRD §10 M08, §9.4, docs/template/notifications.php.
Build: notification classes for every type in the M08 table (database + broadcast + mail +
SmsChannel per notification_preferences + quiet hours), grouping/aggregation rules R-M08-2,
Shared\NotificationBell (live count, lazy dropdown on open, mark all read, synced across tabs),
Shared\ToastStack, Member\Notifications\Index (filters, infinite scroll, grouped by day),
preferences UI stub (full UI in P8.2), prune job (180 d). Wire A04 approval/rejection to
notifications now.
Done when: bell updates live in Dusk; preference off → no mail but in-app still shown (test).
```

### P3.3 — Likes & favorites (M06)
```
Read: PRD §10 M06, §7.2 likes/favorites, §7.3.
Build: likes + favorites tables, ToggleLike (daily limit via EntitlementService, spam guard
R-M06-2, mutual detection, no "unliked" notification), ToggleFavorite (cap, private note),
Shared\LikeButton + FavoriteButton (optimistic Alpine, #[Locked] code), "It's a match!" modal on
mutual, /likes (Liked me — blurred for Free, I liked, Mutual, seen_at dots), /favorites grid with
notes and multi-select. Replace placeholders in profile page, rows and tiles.
Done when: all M06 like/favorite acceptance criteria pass, including live update in Dusk.
```

### P3.4 — Interests & requests (M06)
```
Read: PRD §10 M06 (interest state machine), §12 F02, docs/template/interest.php.
Build: interests + interest_events, SendInterest (quota, contact filter R-M06-3, cooldown),
Accept/Decline/Withdraw, ExpireInterests hourly job, conversation creation on accept with SYSTEM
message, photo_requests/horoscope requests + approve, Shared\InterestButton modal with templated
messages + remaining quota + upgrade modal, /interests tabs with inline accept/decline and bulk
decline. Chat button state updates live on both sides.
Done when: state machine tests for every transition; F02 Dusk flow (like → mutual → interest →
accept → chat button enabled) passes.
```

### P3.5 — Live admin dashboard (A02)
```
Read: PRD §11 A02, §9.3 admin channels.
Build: metric_snapshots table + hourly/nightly snapshot command, Admin\Dashboard (alert strip,
KPI row incl. online now + messages/likes today, charts via Chart.js Alpine wrapper, work-queue
cards with oldest-item age), AdminQueueCountChanged broadcasts from queue-changing actions,
live sidebar badges.
Done when: submitting a profile increments the moderation badge live (Dusk).
```

---

## Phase 4 — Chat

### P4.1 — Chat core: inbox, thread, send, receipts (M07)
```
Read: PRD §10 M07, §9 (9.4, 9.5, 9.7), §7.2 chat tables, §12 F03, docs/template/messages.php.
Build: conversations/participants/messages models, SendMessage (authorize participant + open +
not blocked, can_message entitlement with Free one-reply rule R-M07-3, rate limit 30/min,
idempotent client_id), MarkConversationRead, events MessageSent / MessagesDelivered /
MessagesRead / ConversationUpdated, Member\Chat\Inbox (live reorder, unread badges, search,
archived filter) + Member\Chat\Thread (optimistic bubble via Alpine composer, ticks ✓/✓✓/blue,
day separators, grouping, scroll-to-bottom), Shared\ChatBadge in header + tab bar, toasts when
elsewhere, tab-title count. Mobile: one pane at a time.
Done when: Dusk two-browser round-trip < 1 s with read receipts; duplicate client_id → one row;
Free composer locks after one reply and server returns 403 when bypassed.
```

### P4.2 — Chat part 2: typing, history, extras (M07)
```
Read: PRD §10 M07 (remaining features, rules R-M07-4..7), §9.6, §9.7.
Build: presence-chat channel + typing whisper (throttled, 4 s expiry), online/last seen in header
respecting privacy, load-earlier cursor pagination preserving scroll, reconnect back-fill
(loadSince), unsend within 60 min (MessageDeleted), image messages for paid plans (moderated),
emoji picker, icebreakers for first message, mute/archive, offline email digest (10 min, not
muted), retention job, polling fallback path tested.
Done when: M07 acceptance list fully tested (network-drop test simulated in Dusk).
```

### P4.3 — Content safety & chat moderation (A13)
```
Read: PRD §11 A13, §10 M07 send flow (ContentSafety), §10 M09 report.
Build: ContentSafety service (phone/email/URL/UPI masking in first 10 messages, profanity
EN/ML/Manglish from master lists, spam repetition), message_flags, report-from-chat with message
selection, auto-freeze on 3+ independent reports, ConversationFrozen event, Admin\Chat\FlaggedQueue
+ FlagReview (±3 context, audited reveal), Overview (metadata only), BreakGlass (reason, 30 min,
watermark, audit), Signals (like/interest/message spam), Rules editor with dry-run tester.
Done when: A13 acceptance criteria tested; flagged message increments the admin badge live.
```

---

## Phase 5 — Money

### P5.1 — Plans, checkout & Razorpay (M10)
```
Read: PRD §10 M10, §12 F04, §7.3, docs/template/package.php, checkout.php.
Build: PaymentGateway interface + RazorpayGateway, orders/payments/subscriptions/invoices/coupons
tables, /plans from DB (durations with discounts, comparison table), Member\Billing\Checkout
(coupon validated live, GST line, amount computed server-side from plan key), Razorpay Checkout
JS, /webhooks/razorpay (signature verify, idempotent on payment id) → ActivateSubscription in a
transaction → EntitlementsChanged broadcast → payment_success notification → GST invoice PDF
(gapless numbering) → OrderPaid event. /billing/success polls order status.
Done when: tampered amount has no effect; replayed webhook doesn't double-activate; chat composer
unlocks live after test payment (Dusk with Razorpay test mode or a faked gateway).
```

### P5.2 — Entitlement sweep, billing history & expiry
```
Read: PRD §7.3, §10 M10 (expiry, billing history).
Build: audit every Action that should consume/check an entitlement (likes, interests, contact
views, chat send, visitors, highlight) and add missing checks + tests; Member\Billing\History
(orders, invoices download, usage meters); expiry reminders (7 d, 1 d) and ExpireSubscriptions
job reverting to Free limits; is_premium/highlighted_until denormalisation.
Done when: a table-driven test covers every §7.3 row for Free/Silver/Gold/Diamond.
```

### P5.3 — Admin billing (A06)
```
Read: PRD §11 A06.
Build: Admin\Billing plan editor with live pricing-card preview + entitlement matrix (incl.
likes/day, favourites cap, free-reply toggle) + consistency warning; orders list/detail with
timeline + raw webhooks; stuck orders (re-poll/mark failed/force-activate super_admin);
refunds by category with side-effects in one transaction; subscription views; complimentary
grants; coupons CRUD; GST CSV export; daily reconciliation job with investigate rows.
Done when: A06 rules tested (refund side-effects, plan delete guard, second-level checks).
```

---

## Phase 6 — Trust & support

### P6.1 — ID verification (M09 + A05)
```
Read: PRD §10 M09 (verification), §11 A05, §12 F05, docs/template/verification.php.
Build: Member\Verification\Upload (types, masked Aadhaar, front/back, consent, private encrypted
storage), verification_requests/documents, Admin\Verification\Queue + Review (claim lock, aging
colours, 10-min signed URL viewer with watermark, audit on every view, separate
verification.document.view permission, approve/reject/escalate, fraud flags), Verified badge live,
purge job 90 days (verifies deletion).
Done when: document never reachable by public URL (test); every view audited.
```

### P6.2 — Block, ignore, report, safety centre, support desk (M09 + A10)
```
Read: PRD §10 M09 (safety), §11 A10, §12 F07.
Build: Block (symmetric, silent; removes likes/favorites/pending interests, freezes conversation —
R-M06-4), Ignore (one-way), Report with reasons + evidence, safety centre page, abuse_reports →
AbuseCase aggregation + triage P0/P1/P2 + auto-escalation, Admin\Safety case review (audited
evidence reveal, outcomes incl. ban blocklist, legal hold), support tickets (contact form,
in-app help, canned responses with variables, internal notes, SLA tracking, P0 paging).
Done when: A10 + M09 acceptance tests pass; block effects visible live on both sides.
```

---

## Phase 7 — Broker module (PRD §11A — read the relevant B.x sections each session)

### P7.1 — Broker foundation, application & KYC
```
Read: PRD §11A B.1–B.4, B.13, B.14, B.16 (R-M13-18..25), §8.1.
Build: all broker migrations from B.13 (brokers, broker_kyc, broker_staff, invitations,
referrals, payouts, exports, notes, import batches/rows, profile columns), models, enums;
BureauGate (role ceiling ∩ Owner switch-offs, re-checked per request), middleware broker.active +
broker.staff.active, OTP-on-new-device for OWNER/MANAGER, layouts.broker with permission-filtered
sidebar, Broker\Apply (public, behind broker.self_apply) → APPLIED, Broker\Kyc (PAN + bank,
private encrypted), Broker\Dashboard shell (widgets from B.4 with zero data), Admin\Brokers\
KycQueue (approve/reject, signed PAN view audited) creating the OWNER broker_staff row on approval.
Done when: BureauPermissionMatrixTest skeleton exists and passes for routes built so far; KYC
gate blocks creation routes (403 BROKER_KYC_REQUIRED).
```

### P7.2 — Referral program & materials
```
Read: PRD §11A B.5, B.10 (triggers), B.16 R-M13-1..9, B.19 (referral rows).
Build: ResolveBrokerCode (hooked into registration + wizard step 1, ?ref= cookie from P1.1,
typed code wins, never blocks), public validate endpoint (name + district only),
AttributeCommission listener on OrderPaid (pre-GST subtotal, first paid order, ACTIVE only),
VoidReferralOnRefund, Broker\Referrals (privacy-limited columns), Broker\Materials (link, QR SVG/
PNG, A4 poster PDF), Admin\Brokers\UnverifiedCodesQueue (Levenshtein ≤ 2 suggestions, link/clear,
audited).
Done when: CommissionCalculationTest and RefundClawbackTest pass; unknown code registers fine.
```

### P7.3 — Managed profiles: create, manage, assign, notes
```
Read: PRD §11A B.6 (create, manage, notes), B.11, B.16 R-M13-10/11/14/34/35, B.17.
Build: Broker\ManagedProfiles\Form (one sitting, accordion sections REUSING the M02 Form Objects
and validation — no copy-paste rules), consent checkbox, CreateManagedProfile /
UpdateManagedProfile / SubmitManagedProfile / SetManagedProfileStatus / AssignManagedProfiles,
Index (filters #[Url], bulk assign), Show (moderation status + reason), Notes (note/call/meeting,
follow-up date), FollowUpDue 08:30 notification, A04 provenance flag "Added by broker BRK…",
ManagedProfileModerated event on broker.{id}.
Done when: IdorScopingTest (foreign codes → 404) passes; managed profile goes through A04 like any
profile; staff assigned-only scope enforced.
```

### P7.4 — Client inbox, claim flow & exports
```
Read: PRD §11A B.6 (client inbox, claim), B.7, B.16 R-M13-12/13/15/16, B.18, B.19.
Build: Broker\ManagedProfiles\ClientInbox (accept/decline for client, send interest against bureau
monthly allowance), chat disabled for unclaimed managed profiles (other side sees the notice),
ClaimManagedProfile hooked into registration (explicit "Is this you?" confirm, transaction flips
ownership, unassigns staff, ProfileClaimed event), ExportManagedProfile + BiodataPdfRenderer
(A4, Malayalam-capable font, bureau footer, watermark) + PhotoPackageBuilder (approved photos zip)
via controllers, broker_profile_exports row on every attempt (allowed true/false, user id).
Done when: ExportAuthorizationTest and ClaimFlowTest (incl. export race) pass.
```

### P7.5 — Staff accounts
```
Read: PRD §11A B.2 (matrix), B.8, B.15 (staff events), B.16 R-M13-18..25, B.21 staff criteria.
Build: Broker\Staff\Index (invite with role + scope, seats used/limit), InviteStaff (SMS link
72 h, hashed token), Broker\Staff\AcceptInvite (password + OTP), Staff\Show (permission
switch-offs within role ceiling, assigned profiles, activity, monthly performance), DeactivateStaff
(ForceLogout broadcast, unassign profiles, free seat), Broker\Activity (bureau-scoped audit view),
earnings/KYC/staff pages Owner-only.
Done when: full BureauPermissionMatrixTest (every role × every route/action) passes; deactivated
staff logged out live (Dusk).
```

### P7.6 — Bulk upload part 1: template, upload, validation, preview
```
Read: PRD §11A B.9 (files, flow steps 1–3, parsing edge cases), B.13 import tables, B.17.
Build: ImportTemplateBuilder (xlsx from live master data: Profiles sheet in wizard order,
reference sheets, drop-down validation, Instructions; csv variant), Broker\Import\Upload
(sheet + zip, limits, file sniffing, zip-slip safe), StoreImportBatch, ParseImportBatch job on
`imports` queue (chunks of 100, header auto-map + manual mapping UI, SAME validator as the single
form via a shared ProfileRules class, duplicate checks, photo name matching, formula
neutralisation), ImportProgress events → live progress bar, Broker\Import\BatchReview preview
(status badges, filters, row messages), errors.xlsx download.
Done when: ImportValidationParityTest proves the import and form share one rule set; a 500-row
file validates within budget with live progress.
```

### P7.7 — Bulk upload part 2: fix, consent, import, submit, safeguards
```
Read: PRD §11A B.9 (steps 3–6, batch history, auto-pause), B.16 R-M13-26..33, R-A07-9.
Build: FixImportRow (inline cell edit re-validates the row), re-upload of errors-only file into
the same batch, AttestBatchConsent, ImportBatchRows job (per-row transaction through
CreateManagedProfile, idempotent via imported_profile_id, photos through media pipeline),
caps (500/file, 1,000/day, 100/day new bureau), "Submit all complete" → A04 with batch flag,
auto-pause at > 40 % rejections (ImportsPaused event), Broker\Import\Batches history, cancel,
30-day file purge job.
Done when: ImportPipelineTest covers duplicates, consent, caps, idempotent re-run, auto-pause.
```

### P7.8 — Payouts & broker admin screens (A07)
```
Read: PRD §11A B.10, B.12, B.16 R-A07-1..11, B.21 admin criteria.
Build: Admin\Brokers\BrokersIndex (all columns, create broker), BrokerShow tabs (Overview edits:
commission, allowance, seat limit, caps, suspend/deactivate; Referrals; Managed Profiles with A04
links; Staff; Imports; Earnings; Payouts; Exports; Disputes; Activity), PayoutsIndex (create run,
preview, second approver > ₹50k, < ₹500 roll-forward, row lock, mark paid with UTR, statement
PDF), ExportsAudit (append-only, anomaly highlight), ImportsMonitor (pause/resume, caps, cancel),
Disputes (R-A07-10 flow → A10 case), fraud signals on directory + A02 alerts. Owner live events
on broker-owner.{id}.
Done when: PayoutRunTest passes; deactivating a bureau blocks Owner + staff instantly (test).
```

### P7.9 — Broker security sweep
```
Read: PRD §11A B.18, B.22.
Task: Act as an attacker with each bureau role and as another bureau. Enumerate every route,
Livewire action, channel and export in the broker module; try foreign ids, tampered #[Locked]
props, typed URLs, stale sessions after deactivation, KYC revoked mid-flow, claim/export race.
Write a failing test for each gap found, then fix. Report a table of what was tested.
Done when: all ★ tests in B.22 pass and the report shows no open gaps.
```

---

## Phase 8 — Content, settings & communication

### P8.1 — CMS & public pages (M12 + A08)
```
Read: PRD §10 M12, §11 A08.
Build: success_stories, branches, faq_items, pages (versioned legal), testimonials, banners, home
blocks, ad slots, contact_submissions tables; replace DemoContent with DB; A08 screens with
editorial workflow, content.edit vs content.publish, consent gate for stories, signed preview,
sanitised WYSIWYG; contact form with Turnstile + honeypot → inbox; sitemap.xml + community
landing pages; featured members on home (premium + verified + photo visible to all).
Done when: A08 acceptance tests pass; home page fully DB-driven.
```

### P8.2 — Settings & privacy, success-story submission (M14 + M16)
```
Read: PRD §10 M14, M16.
Build: /settings tabs: Account (change mobile with double OTP, email verify, password, sessions),
Privacy (all privacy_settings incl. incognito + contact filter — wire into search/interest
checks), Notifications matrix + quiet hours, Blocked & ignored, Hide/Delete with reasons (30-day
recovery, anonymise job), Download my data (queued zip, 24 h link). M16 story submission with
partner confirmation → A08 PENDING, auto-hide both profiles.
Done when: each privacy toggle has a test proving its effect elsewhere (search, profile, chat).
```

### P8.3 — Notifications, campaigns & journeys (A14)
```
Read: PRD §11 A14.
Build: template manager (email Markdown, SMS DLT ids, in-app copy, versioned, test send),
announcements (live broadcast to online users), campaign builder (segments, channels, schedule,
throttle, preferences + quiet hours respected, second approval > 10k, cancel, stats), automated
journeys (incomplete wizard, pending interest, expiry, win-back, inactive), delivery log.
Done when: a campaign to a test segment respects opt-outs (test).
```

### P8.4 — Settings & configuration (A15)
```
Read: PRD §11 A15.
Build: Admin\Settings screens for site settings, business rules (validated, audited), entitlement
matrix link, feature flags, integrations status with test buttons (Razorpay, MSG91, mail, S3,
broadcast driver connection test) — secrets never editable/visible in UI.
Done when: changing a business rule (e.g. interest expiry days) takes effect without deploy (test).
```

---

## Phase 9 — Hardening & launch

### P9.1 — Reports & analytics (A09)
```
Read: PRD §11 A09.
Build: nightly reports:snapshot, report catalogue + report views (growth, engagement incl. likes/
chat metrics, revenue, trust & ops, marketplace health), date presets + compare, CSV export with
formula neutralisation (queued for large), weekly KPI digest.
```

### P9.2 — Audit viewer & system health (A12)
```
Read: PRD §11 A12.
Build: AuditLogIndex (filters, FULLTEXT, diff view), EntityTimeline component mounted in member/
broker/order detail pages, SystemHealth (Horizon summary, scheduler history, webhook replay,
outbound delivery, Reverb connections + broadcast lag), alert thresholds, MySQL REVOKE UPDATE/
DELETE on audit_logs in a deploy script.
```

### P9.3 — Font Awesome 6, accessibility & performance pass
```
Read: CLAUDE.md template rules, PRD §14.
Task: upgrade Font Awesome 4.7 → 6 across all views (class map), axe-core Dusk checks on key
pages and fix issues, aria-live for chat/toasts, Lighthouse on home/search/profile/dashboard
(LCP < 2.5 s on 4G), N+1 hunt with preventLazyLoading, add missing indexes from slow-query log.
```

### P9.4 — Security review & load test
```
Read: PRD §14, §16.
Task: OWASP ASVS L2 walkthrough per area (auth, session, access control, input, files, payments,
real-time, admin), fix findings with tests; k6 HTTP scenario (search, profile, dashboard) and
WebSocket scenario (5k connections, 200 msg/s) against staging; report results vs §14 targets.
```

### P9.5 — Deployment
```
Read: PRD §17.
Build: Nginx configs (app + Reverb proxy), Supervisor programs (reverb, horizon), deploy script
(zero downtime: build, migrate --force, caches, horizon:terminate, reverb:restart, smoke test),
scheduler cron, backups (DB daily + binlog, S3 versioning), Sentry + uptime checks for https and
wss, APP_INDEXABLE flip checklist, launch runbook.
```

---

## Utility prompts

**Resume an interrupted session**
```
Read CLAUDE.md, docs/progress.md and `git status` / `git diff`. We were in session <ID>. Summarise
what is done and what is left against the session's "Done when", then continue with the next step.
```

**Debug a failure**
```
This fails: <paste error / failing test / steps>. Find the root cause before changing anything.
Explain it in 3 lines, then fix it and add a regression test. Don't weaken or skip tests.
```

**Review before commit**
```
Review the uncommitted diff against CLAUDE.md and the PRD sections for this session: missing
authorization, missing #[Locked], unscoped queries, N+1, business logic in views, missing tests
for a PRD rule, hardcoded limits. List findings, then fix them.
```
(Or: "Use the oppam-reviewer agent to review the current changes." — it runs all gates and
returns the completion report. Your global `quality-gate` agent also loads these project skills.)

**Security check on one area**
```
Act as an attacker against <area>. List every way a user could access or change data they
shouldn't. Write a failing test for each real gap, then fix.
```

**PRD change**
```
We are changing <rule/feature> to <new behaviour>. Update the PRD section(s), docs/decisions.md,
the code, and the tests together. Show me every place affected before editing.
```

**Explain what was built (for your own review)**
```
Explain, in plain language for a non-developer, what session <ID> built: screens, what each
button does, which rules are enforced where, and how to test it by hand in the browser.
```

**Manual test script**
```
Write a step-by-step manual test script for <feature> covering the happy path, the main
error cases, and mobile (360px). Include the seeded test accounts to use.
```
