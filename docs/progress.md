# Build Progress

Tick items as they are finished (tests green, quality gate passed, tried in the browser,
committed). Session details and prompts: `docs/build-prompts.md`. Spec:
`docs/Oppam_Matrimony_PRD_v5_Laravel_Livewire_Realtime.md`.

**Current phase:** 0 — Foundation
**Next session:** P0.4 — Core schema, masters & seed data

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
- [ ] P0.4 Core schema, masters & seed data
- [ ] P0.5 Admin auth, RBAC & audit (A01 + A12 core)
- [ ] P0.6 Settings, feature flags & entitlements service

## Phase 1 — Identity & profiles
- [ ] P1.1 Registration, OTP, login (M01)
- [ ] P1.2 Wizard steps 1–3 (M02)
- [ ] P1.3 Wizard steps 4–6, completeness, submit (M02)
- [ ] P1.4 Photos & media privacy (M11)
- [ ] P1.5 Profile view, own & others (M03)
- [ ] P1.6 Moderation queues (A04)
- [ ] P1.7 Member management (A03)
- [ ] P1.8 Master data management (A11)

## Phase 2 — Discovery
- [ ] P2.1 Search service & search page (M04)
- [ ] P2.2 All profiles & saved searches (M04)
- [ ] P2.3 Dashboard & my matches (M05)
- [ ] P2.4 Daily matches job & visitors (M05, M15)

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
- [ ] Launch: APP_INDEXABLE=true, live Razorpay keys, monitoring green

## Notes / deviations
<!-- Record anything built differently from the PRD, with the reason and a link to docs/decisions.md -->
- P0.1: dev env is Windows + XAMPP (MariaDB, no Redis locally), Livewire 4 instead of 3, package routes
  locked down, public routes pinned to the app domain. All in docs/decisions.md (2026-09-27).
- Local URLs: http://localhost:8000 (site), http://admin.localhost:8000 (admin). Don't use 127.0.0.1.
- P0.3 → P1.1: replace the home hero form with `<livewire:public.quick-register>` in the same session that adds the
  `register` route (the hero form's submit is disabled only while that route is missing).
- P0.3 → P0.4: replace `DemoContent::plans()` with plans from the `plans` / `plan_features` tables.
- P0.3 → P9.5: nginx needs the `/index.php` → `/` 301 (Apache has it in public/.htaccess).
