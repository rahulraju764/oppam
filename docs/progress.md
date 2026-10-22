# Build Progress

Tick items as they are finished (tests green, quality gate passed, tried in the browser,
committed). Session details and prompts: `docs/build-prompts.md`. Spec:
`docs/Oppam_Matrimony_PRD_v5_Laravel_Livewire_Realtime.md`.

**Current phase:** 5 — Money (Phase 4 complete, 2026-10-21)
**Next session:** P5.3 — Admin billing (A06) — plan written, waiting on 3 owner answers (second-level checks, refund scope,
refund categories); first run P5.2's Dusk step

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
- [x] P2.3 Dashboard & my matches (M05) — 2026-10-04 (reopened after an unreviewed first pass; reviewer READY in round 1: MatchFunnel tabs (All = meets my prefs, Mutual = also accepts me), cached counts, lazy bundled dashboard strips from daily_matches, /matches, scorer fixes, Dusk desktop + 375px)
- [x] P2.4 Daily matches job & visitors (M05, M15) — 2026-10-04 (reopened after an unreviewed first pass that listed blocked members; reviewer READY in round 2 after 3 Majors fixed: retry_after > job timeouts + per-member lock, 05:00 IST end-to-end + schedule tests, indexed batched prune)

## Phase 3 — Real-time core & engagement
- [x] P3.1 Reverb, Echo, channels & presence — 2026-10-05 (reviewer READY in round 2 after 1 Blocker + 3 Majors fixed: user ULID in presence rosters → profile code, best-effort ForceLogout (LiveSignal), flaky live-logout Dusk test, HTTP impersonation/security tests; owner review of the commit — rule 5)
- [x] P3.2 Notifications system (M08) — 2026-10-06 (reviewer READY in round 3 after 6 Majors + 1 Blocker fixed: mandatory events forced a template-less SMS, row / live-signal order (InAppChannel: one job), purge left notifications, support session could read / delete, list collapsed to page 1, a flaky test; owner decision: no notification email until email verification, M14)
- [x] P3.3 Likes & favorites (M06) — 2026-10-06 (reviewer READY in round 2 after 2 Majors fixed: favourite slots stuck on profiles no longer available, favourites + notes readable in an admin support session (R-M06-6); found on the way: P3.1's "End session" sent the admin to the sign-in page — fix held for owner review, rule 5)
- [x] P3.4 Interests & requests (M06) — 2026-10-11 (reviewer READY in round 3 after 2 Majors fixed: the new "favourites only" search filter listed a member's bookmarks to an admin support session, withdraw-and-send-again could notify the same member without limit; round 2 could not run its tests for lack of memory, round 3 confirmed)
- [x] P3.5 Live admin dashboard (A02) — 2026-10-15 (reviewer READY in round 2 after round-1 fixes: own 2 s client for "online now", snapshot / queue-count indexes + one grouped queue query, live "being reviewed by …", after-commit rescued signup tick, system alerts need dashboard.view, finished-day change; A03 Engagement tab; Chart.js 4 admin-only; Dusk: submit → badge + card live)

## Phase 4 — Chat
- [x] P4.1 Chat core: inbox, thread, send, receipts (M07) — 2026-10-16 (reviewer READY in round 1, Minors only; owner reviewed and approved the commit — rule 5: an admin support session sees no message text and the chat / inbox channels refuse it)
- [x] P4.2 Chat part 2: typing, history, extras (M07) — 2026-10-16 (reviewer READY in round 2 after 2 Majors fixed: the digest job re-read every member with old unread messages (now SQL-filtered, fixed query count), decisions not recorded; owner confirmed both decisions in decisions.md)
- [x] P4.3a Content safety, flags, report from chat, freeze, flagged queue (A13 + M09 report) — 2026-10-18 (committed by the owner in bd6fbc8 after reviewer round 1; confirming review NOT READY with 2 Majors fixed: a moderator's freeze on a report-frozen thread was later undone, two simultaneous reports could miss the auto-freeze (profile-row locks); round 2 READY, Dusk freeze 1.1–1.6 s + live badge)
- [x] P4.3b Chat overview, break-glass, signals, rules editor (A13) — 2026-10-21 (built 2026-10-18, committed by the owner in cb7a604 + 165284a without a review; reviewed afterwards: READY TO COMMIT in round 1, 0 Blockers / 0 Majors, 7 Minors open — see Notes; Dusk / browser widths not run)

## Phase 5 — Money
- [x] P5.1 Plans, checkout & Razorpay (M10) — 2026-10-21 (reviewer READY in round 2 after 1 Major fixed: SimulateTestPayment's refusals untested; Dusk: composer unlocks live 1.4–3.0 s after a fake-gateway payment; NOT committed — payments change, owner review first (rule 5))
- [ ] P5.2 Entitlement sweep, billing history & expiry — 2026-10-22: reviewer READY in round 2 after 1 Major fixed (ExpireSubscriptions
  expired PAUSED plans + resume didn't re-sync flags); round-2 Minors fixed (EntitlementsChanged `until` on pause / resume, event asserted).
  NOT ticked yet: Dusk unrun (tests/Browser/Mobile/BillingTest.php /billing step — dev servers were stopped for low memory) and NOT
  committed (ActivateSubscription edit → owner review with P5.1, rule 5). Open Minors: matrix lacks "limit−1 allowed" for favourites / contacts; `isFeatured` shows on every
  card list, not only search; /billing no loading state on pagination, query count unmeasured; migration down() not executed.
- [ ] P5.3 Admin billing (A06)

## Phase 6 — Trust & support
- [ ] P6.1 ID verification (M09 + A05) — 2026-10-22: built with Claude's defaults (decisions 2026-10-22, owner to confirm);
  see the completion report. NOT ticked: reviewer verdict + Dusk pending (dev servers stopped); NOT committed.
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
- P5.1 → before launch (P9.5): the owner supplies the seller's legal name, GSTIN, address and SAC (A15 `billing.seller_*` —
  placeholders today; an invoice without a GSTIN says it is not a valid tax invoice); Razorpay live + test keys, webhook URL
  `/webhooks/razorpay` (payment.captured, payment.failed, order.paid) and **automatic capture** on; register the MSG91
  `payment_success` template (variables: plan name, end date); `PAYMENT_DRIVER=razorpay` in production.
- P5.1 review Minors, open: (1) the coupon field is checked live (400 ms debounce) and every pause counts against
  `billing.coupon_attempts_per_hour` — check on blur / an Apply button, or count only plausible codes; (2) the invoice prints
  the letter state code ("KL") — GST invoices use the numeric state code (Kerala 32): add it to master_states or a setting.
- P5.1 → P5.2: billing history (orders, invoice downloads — the route exists), usage meters, expiry reminders + the job that
  ends subscriptions and clears `is_premium` (today `is_premium` is set on activation and never cleared), `highlighted_until`.
- P5.1 → P5.3 (A06): stuck orders (`failure_reason` amount_mismatch, logs `billing.amount_mismatch` / `billing.extra_payment` /
  `billing.coupon_over_limit`), raw webhook viewer (`payment_webhook_events`, payer details redacted), refunds, coupon admin,
  monthly GST CSV, reconciliation job; P7.8: the `OrderPaid` listener (broker commission).
- P5.1 → P8.2 (M14): the invoice email (no email before verification, 2026-10-06); today the invoice is downloaded from
  `/billing/success/{order}`.
- P5.1 → P9.3 / P9.4: real Razorpay test-mode keys not yet exercised end to end (fake gateway only); time the live unlock
  with OPcache (1.4–3.0 s locally without it, through `queue:listen`); a member with no profile on SimulateTestPayment untested
  (trivial 404).
- P5.1 local setup: `php artisan migrate` and `php artisan db:seed --class=PlansSeeder` (insert-only: adds the 3 / 6 / 12-month
  prices) and `--class=SettingsSeeder` (billing settings) — run on the local database 2026-10-21; `DemoCouponsSeeder`
  (WELCOME10, FLAT200) runs only on a fresh local seed.
- P4.3b review Minors (round 1, 2026-10-21), open: (1) `EngagementSignals::raise()` reads then inserts / increments with no
  lock — parallel requests can make two OPEN rows or lose a count (lock or `increment()`); (2) `DetectEngagementSignals` takes
  `limit(200)` before filtering already-raised members, unordered — 200+ standing offenders hide new ones (filter in SQL);
  (3) `ProfanityMatcher` casts a failed `preg_replace` (null) to "" — keep the text and flag, as `mask()` does; (4) BreakGlass
  shows the editable codes A · B but opens the locked `threadId` — render the found thread's codes or reset on edit; (5)
  `Signals::$selected` id is not `#[Locked]` (re-read + re-authorized on every action; record or pass as an argument);
  (6) every refused favourite writes to `engagement_signals` — raise once per window; (7) the break-glass watermark is 24 fixed
  spans from the top — long transcripts may be unmarked lower down (repeating background) — check with a Dusk screenshot.
- P4.3b not verified in a browser: no Dusk test for the A13 admin screens; widths 320–1440 px → P9.3. The decisions row of
  2026-10-18 is still marked "owner to confirm".
 - P4.3a → P4.3b: the rules editor (today the patterns are RuleBasedContentSafety constants; per-rule mask / flag / block and
  a dry-run tester), Overview (metadata only), BreakGlass, Signals (REPETITION flags and like / interest spam →
  engagement_signals with one-click actions). Images are never scanned automatically — a reported photo is reviewable
  (admin flagged-photo route, after the audited reveal). Images in the first 10 messages stay allowed (decisions 2026-10-16).
- P4.3a → P6.2: a block freezes the pair's thread through ChatFreezer (add FreezeReason::Block) — the live
  ConversationFrozen already locks both composers (M07 acceptance "a blocked pair's thread becomes read-only live");
  escalated flags become A10 cases; profile reports reuse the `reports` table (source PROFILE); a blocked pair can no longer
  report from chat (ChatAccess hides the thread: 404) — "block, then report" needs the profile report path there.
- P4.3a → P4.3b: the MONEY pattern is broad ("need … amount", "Rs 500", IFSC-shaped tokens) — measure the queue and tune it
  in the rules editor; the freeze reached the composers in 0.7 – 1.8 s locally without OPcache (bound 2 s) — time it with
  OPcache / in CI.
- P4.3a → P9.4: no claim / lock on a flag review (two moderators can open the same flag; the second decision is refused
  409); the A13 queue count is one query per `.queue.count` refresh; ReportChatMessages counts distinct reporters on each
  report (indexed); `chat.max_per_minute` still applies before ContentSafety's repetition query.
- P4.3a review round 2 Minors → P9.4: ChatFreezer::unfreezeReportFrozen reads the OTHER member's report count without a lock
  (a dismissal racing that member's 3rd report can reopen their shared thread); the report / decision transactions have a single
  attempt on a deadlock (an error toast, clean rollback) — consider `DB::transaction(…, 3)`.
- P4.3a → P9.3: admin chat screens and the report dialog at 320 / 768 / 1024 / 1440 px (checked: thread 375 px, admin 1280).
- P4.2 → P9.4: Reverb client-event rate limiting (`config/reverb.php`, off by default) for typing whispers (§9.8);
  `ThreadWindow::MAX` (600) — "load earlier" stops working after ~20 pages and each refresh re-reads the window; the image
  route's `throttle:120,1` on image-heavy threads; image file copy / delete inside the DB transaction (orphan file on a
  rollback); SendIcebreaker checks "has written" outside the thread lock (the free reply is still safe); a message arriving
  between MarkMessagesDelivered's grouped read and its UPDATE gets ✓✓ late; tests: tampered `fromId`, suspended member on
  unsend / mute / image route / icebreaker, the 10 s fallback detection + `realtime.polling_fallback` flag in Dusk.
- P4.2 → P9.3: photo button focus ring (label over a hidden input); thread widths 320 / 768 / 1024 / 1440.
- P4.2 not built: the optional new-message sound (PRD "optional").
- P4.1 → P4.2: presence-chat join + typing whisper, online / last seen in the thread header, load earlier (ThreadWindow
  keeps the newest 30 today), reconnect back-fill by id (today a reconnect re-reads the newest 30), unsend
  (`MessageText` already shows "This message was deleted"), images, emoji, icebreakers, mute, the offline email digest
  (`new_message` writes no bell row), retention job (R-M07-6: today only the purge deletes a thread, once both members
  are purged), polling fallback test (the thread already re-reads every 10 s in fallback mode), the optional sound.
- P4.1 → P4.3: ContentSafety in SendMessage (masking in the first 10 messages, profanity, repetition), message flags,
  report from chat, auto-freeze; `ConversationFrozen` live event (today a frozen thread is read-only on the next render).
- P4.1 → P6.2 (block): freeze the pair's thread on a block (ChatAccess already hides a blocked pair's thread: 404).
- P4.1 → P8.2 (M14): the read-receipts switch (the rule is live: `privacy_settings.read_receipts`).
- P4.1 → P9.4: no rate limit on mark read / delivered / archive or the inbox name search (all owner-scoped and cheap);
  measure /messages and the header badge (UnreadChats: two queries per page; MarkMessagesDelivered on page load when
  something is unread); the free-reply count and "has the other member written" are two counts per send.
- P4.1 review Minors (round 1), open: (1) R-M07-7 — a SENDER with read receipts off still receives `.message.read`
  (reader code + time) in the socket frames; the screen is right (blue needs both on) — choose MessagesRead vs
  MessagesDelivered with `ReadReceipts::shownBetween()`; (2) SendMessage counts the rate limit after the transaction,
  so parallel sends can slightly exceed `chat.max_per_minute`; (3) ChatBadge trusts `total_unread` from its own echo
  payload (a member can only fake their own badge) — recount; (4) MarkMessagesDelivered loads all waiting messages
  unbounded — chunk / group by thread; (5) widths 320 / 768 / 1024 / 1440 not tested (375 + 1280 only) — P9.3;
  (6) the PRD's right rail "profile summary + safety tips" is the template's ads rail for now; (7) the < 1 s delivery is
  shown by single local samples (668–1520 ms without OPcache) — run the Dusk test with OPcache / in CI and time a p95.
- P4.1 local dev: `REVERB_HOST=127.0.0.1` in .env (see .env.example) — with `localhost` every broadcast waited ~2 s on
  Windows; consider enabling OPcache in XAMPP's php.ini (each request spends ~0.5–0.8 s compiling without it).
- P3.5 → later modules: the dashboard shows only real data — P5.3 adds revenue today / MTD, the revenue-by-plan chart and
  payment ticks; P6 the verification / report / ticket cards; P4.3 flagged chats; P7 broker KYC; P5 stuck orders; P9.2
  webhook-failure / error-rate alerts (decisions 2026-10-11).
- P3.5 review Minors (round 2), open: (1) between 00:00 and the first hourly snapshot (~00:35 IST) the "today" flow tiles
  (new members, messages, likes) fall back to yesterday's row — show 0 / a dash for flows without today's row
  (`DashboardMetrics`); (2) each `.queue.count` re-renders the whole dashboard (~10 queries) — re-read only the cards or
  cache the alerts ~30 s (measure in P9.4); (3) the live signup count can read one high when two signups seed the cache
  together (best-effort, the hourly snapshot corrects it); (4) LiveQueueBadgeTest waits for the socket, not the
  `private-admin.queues` subscription; (5) `.queue.claim` isn't `toOthers()`; (6) the sidebar badge's hidden "waiting"
  text is only rendered when the server count was > 0; (7) dashboard widths 320–1440 px not checked in a browser — P9.3.
- P1.4 local setup: run `php artisan storage:link` once (public disk for photo conversions). Demo photos are added by
  DemoProfilesSeeder only on a fresh seed (`php artisan migrate:fresh --seed`, LOCAL ONLY).
- P1.4 → P1.5: profile pages show photos only through `PhotoUrls::forViewer()`; horoscope links via `HoroscopeAccess::link()`.
- P1.4 → P1.6: photo queue uses `moderation_items` PHOTO rows (subject_id = media uuid) and `media.phash` for duplicates.
- P1.4 → P2.x: `PhotoUrls` queries per profile; add a batch `forViewers()` (eager-loaded) before search/list cards use it.
- P1.4 → P9.5: S3 public bucket with listing disabled; `media-library:regenerate` after an APP_KEY rotation (conversion names are keyed).
- P3.4 → P4.1 (chat): the Chat button (`<x-engagement.interest-button>`, state "connected") only says chat opens soon —
  give it the conversation link (it looks for a `member.messages` route); the `messages` table and the SYSTEM message
  "You are now connected" already exist (body stored in English: translate when shown); `AcceptInterest` links an existing
  conversation of the pair as it is.
- P3.4 → P6.2 (block): a new block withdraws / closes pending interests and requests of the pair and freezes their
  conversation; decide whether an approved photo / horoscope request can be revoked on its own (today only by changing the
  visibility setting); frozen / closed conversation rules when a pair connects again.
- P3.4 → P8.2 (M14): the privacy screen where members set ON_REQUEST / ACCEPTED_ONLY (the rules are live now); whether a
  member with a hidden / unapproved profile may accept an interest (today: yes).
- P3.4 → P8.3 (A14): the day-3 reminder for unanswered interests.
- P3.4 → P9.4: the interest button isn't re-read on a socket reconnect outside /interests; no rate limit on accept / decline /
  withdraw; "hide contacted" in search and the /interests lists count an interest for up to an hour after it expired (until
  the hourly job); EXPLAIN `Connections::statesFor` on the bench data; a "My favourites only" label may still show in a
  support session though the filter is inert.
- P3.4 → P9.5: run `php artisan db:seed --class=MastersSeeder` once so the five interest messages exist (A11 "Interest
  messages"); register the MSG91 templates for interest received / accepted (variable: the other member's profile code).
- P3.3 → owner review (rule 5): `resources/js/realtime.js` + `components/impersonation-banner.blade.php` — the tab that
  presses "End session" no longer reloads itself onto the sign-in page (P3.1 bug; both impersonation Dusk tests failed
  without it). Committed by the owner in 1f401be (2026-10-11).
- P3.3 → P3.4: "favourites only" search filter; "send interest to all selected" on /favorites; Send Interest in the
  "It's a match!" dialog; exclude interest-sent profiles from daily matches (liked ones already are).
- P3.3 → P4.3 (A13): screens for `engagement_signals` (LIKE_SPAM rows exist now); the like-spam window is fixed (10 min from
  the first like), not sliding — tighten there if abuse shows it matters.
- P3.3 → P6.2 (block / ignore): creating a block removes likes, favourites and pending interests both ways (R-M06-4) and
  the notification rows naming the other; decide whether a like from an incognito / ignored member should notify (today it
  does, though that member is never listed on /likes).
- P3.3 → P9.4: measure /likes and the dashboard (six strips, up to 60 nested like buttons); `BuildDailyMatches` passes every
  liked profile id as a list (use a NOT EXISTS for heavy likers); the quota dialog and the Gold "new like" row are covered by
  Livewire tests only, not in a browser.
- P3.3 → P1.7a carry-over still open: the A03 member page "Engagement" tab (likes / interests; favourites as a number only).
- P3.2 → P3.3 / P3.4 / P4 / P5 / P6: like, mutual-like, interest, message, photo / horoscope request, verification, payment and
  safety notifications extend `MemberNotification` (event in `NotificationEvent`, `actorProfileId()` for R-M08-1); likes use
  `OncePerDay` (R-M08-2). Any new table that names another member in a notification: nothing to add — the purge already
  deletes rows by `data->actor_code`.
- P3.2 → P6.2 (block): on a new block, delete both members' notification rows that name the other (`data->actor_code`) —
  today a "<name> viewed your profile" row written before the block keeps the name.
- P3.2 → P8.2 (M14): email verification (until then NO notification email goes out — owner decision 2026-10-06), the
  notification preferences screen (mails link to /notifications, which has no settings yet), and an open /notifications
  list in another tab reacting to `.notifications.read` (only the bell count syncs today).
- P3.2 → P8.3: email digests (likes daily, views weekly, messages after 10 min offline); "daily roll-up" of profile-view
  rows for Free members (today: one "Someone viewed your profile" row per viewer per day).
- P3.2 → P9.4: two bell components per page (desktop + mobile) = two count queries per load — merge or measure; the purge's
  unindexed `data->actor_code` match; same-second notifications have no order among themselves (`timestamps(6)` if it matters).
- P3.2 → P9.5: register the MSG91 DLT notification templates (`MSG91_TPL_*` in .env.example; `profile_rejected` is shared by
  "needs changes" and "content rejected" and sends no variables — match the approved template text).
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
- P2.4 → P9.4: measure daily-match generation throughput on the 100k bench data (per-member build time × members ÷ heavy-queue workers) so every batch lands by 06:00 IST (M05 acceptance); P3.3 / P3.4: add the liked / interest-sent exclusions to BuildDailyMatches.
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
- P1.6 → P9.4 (moved out of Phase 2, 2026-10-04): move the upload-time duplicate-photo scan (UploadProfilePhoto → ModerationFlags::forPhoto) to a job on the `media` queue as the media table grows.
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
