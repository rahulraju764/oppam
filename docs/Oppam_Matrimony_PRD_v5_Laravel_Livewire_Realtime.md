# Oppam Matrimony — Product Requirements Document
## v5 — Full-Stack Laravel Edition: Laravel 12 + Livewire 3 + Laravel Reverb (Pusher protocol) + MySQL 8

**Prepared as:** a build-ready, module-wise specification and engineering handoff for the complete
Oppam Matrimony product — member site, broker portal, and super-admin panel — built as **one Laravel
application**.
**Audience:** product owner, engineering leads, QA, and AI coding agents implementing each module.
**Version:** 5.0 | **Date:** 27 September 2026 | **Status:** Draft — ready for engineering breakdown
**Source material:**

1. The 31-page PHP front-end template at `D:\oppam_template\template` (Bootstrap 5.3, Swiper 11,
   custom CSS design tokens, `CLAUDE.md` engineering notes). It is the **visual source of truth** —
   every member-facing screen in this PRD maps to a page in that template (§6).
2. `Oppam_Matrimony_PRD_v4_Laravel_Admin.md` — the v4 specification. **The Broker Portal (M13) and
   Broker Management (A07) are carried over from v4 in full** (business rules R-M13-1…17 and
   R-A07-1…8 unchanged), re-expressed for Livewire. The v4 data model, entitlement table, admin
   RBAC and moderation rules are also reused wherever not explicitly changed here.
3. Competitor research on KeralaMatrimony / BharatMatrimony, Shaadi.com, Jeevansathi, M4Marry and
   Chavara Matrimony (§3, sources at the end).

---

## 0. Document Control

### 0.1 What changed in v5 — read this first if you know v4

| # | Change | Why |
|---|---|---|
| 1 | **The member site and broker portal are no longer Next.js.** They are Laravel Blade views converted from the PHP template, made interactive with **Livewire 3 + Alpine.js**. The admin panel was already Livewire in v4. The whole product is now **one Laravel codebase, one deployment, one language.** | The template is already PHP/Blade-shaped; converting it directly is far cheaper than re-building it in React. One stack means one team can own everything. |
| 2 | **Real-time layer = Laravel Reverb** (first-party WebSocket server that speaks the **Pusher protocol**). Client = **Laravel Echo + pusher-js**. A single `.env` switch (`BROADCAST_CONNECTION=reverb` or `pusher`) moves to hosted **Pusher Channels** with zero code change. | User requirement: "Laravel + Livewire + Pusher / Reverb". Reverb is free and self-hosted; Pusher is the managed fallback. |
| 3 | **Chat is fully real-time with no page refresh** — messages, typing indicator, online presence, read receipts (✓ / ✓✓ / blue ✓✓), unread badges, all pushed over WebSocket into Livewire components. | User requirement. |
| 4 | **Notifications are real-time** — bell badge and toast update live via Laravel's `broadcast` notification channel; the dropdown and the notifications page lazy-load and infinite-scroll. | User requirement: "load notifications". |
| 5 | **New engagement primitives: Like and Favorite**, alongside the existing Interest. Like = free one-tap "I like your profile" signal; Favorite = private bookmark (replaces v4 "shortlist"); Interest = formal proposal that unlocks chat (§M06). | User requirement. |
| 6 | **Search & filters are Livewire-reactive** — results update as filters change, filters are reflected in the URL (`#[Url]`), saved searches with alerts. | User requirement. |
| 7 | **SPA-style navigation** across the whole member area using `wire:navigate` — no full page reloads between pages; the WebSocket connection survives navigation. | "Chat with page not refresh" should hold across the site, not only on the chat page. |
| 8 | **Competitor feature matrix added (§3)** and several competitor features promoted into v1 (privacy controls, who-viewed-me, profile boost/highlight, ignore list, safety centre) with the rest placed on the roadmap. | User requirement. |
| 9 | **Admin panel completed** — 15 admin modules (A01–A15), adding **A13 Chat & Content Safety**, **A14 Notifications & Campaigns**, and **A15 Settings & Configuration** to v4's A01–A12. | User requirement: "complete admin feature and entire working". |
| 10 | **Broker bulk upload and broker staff accounts moved from phase 2 into v1** (reverses v4 R-M13-17). Bureaus get Owner / Manager / Data Entry / Telecaller logins with per-role permissions, profile assignment and follow-up notes, plus an Excel/CSV + photo-zip importer with live validation, inline fixing, consent attestation and moderation safeguards (**§11A — dedicated broker chapter**; rules R-M13-18…35, R-A07-9…11). | Product decision, 27 Sep 2026: bureaus bringing existing client registers need both on day one. |

What did **not** change from v4: broker rules R-M13-1…16 and R-A07-1…8 (only R-M13-17 is reversed), plan prices, entitlement numbers,
the moderation-before-publish rule, ID-document storage rules, money-in-paise, ULID keys, admin 2FA,
audit logging of every admin write.

### 0.2 How to read this document

- **§1–§7** — product framing, competitor research, stack, architecture, template conversion map,
  data model. Read once, in order.
- **§8** — authentication, roles and permissions.
- **§9** — the **real-time architecture** (Reverb, channels, events, Livewire listeners). Read this
  before M06, M07, M08.
- **§10** — member & broker modules **M01–M16**.
- **§11** — admin modules **A01–A15**.
- **§11A** — the **dedicated Broker / Bureau chapter**: everything broker-related (portal, staff,
  bulk upload, commission, admin side, data, rules, flows, tests) in one place.
- **§12–§19** — cross-module flows, route index, non-functional requirements, configuration,
  testing, deployment, phased plan, open questions.

Each module uses the same compact template: **Purpose · Features · Screens & Livewire components ·
Working flow · Business rules · Data · Real-time events (if any) · Security · Edge cases ·
Acceptance criteria.**

---

## 1. Executive Summary

Oppam Matrimony is a **Kerala-focused matrimonial platform** for Malayali brides and grooms and
their families, with first-class support for the way matchmaking really happens in Kerala: through
**families, local brokers and bureaus**, not just individuals.

The product has three surfaces, all served by one Laravel 12 application:

| Surface | URL | Users | Built with |
|---|---|---|---|
| Member site (public + logged-in) | `oppam.in` | Visitors, members (brides/grooms/parents) | Blade (from template) + Livewire 3 + Alpine |
| Broker portal | `oppam.in/broker` | Verified marriage brokers / bureaus | Blade + Livewire 3 (same member layout, broker menu) |
| Admin panel | `admin.oppam.in` | Staff: super admin, moderators, verification officers, support, finance, content | Blade + Livewire 3 (admin layout) |
| WebSocket server | `ws.oppam.in` | All logged-in users | Laravel Reverb (Pusher protocol) |

**Core value:** verified profiles, strong privacy controls, fast reactive search, and real-time
conversation — Likes, Favorites, Interests, Chat and Notifications all update live without a page
refresh.

### 1.1 What "done" looks like for v1

1. A visitor can register (mobile OTP), complete the 6-step profile wizard, and have the profile
   approved by a moderator.
2. A member can search with 20+ filters, get daily matches, Like / Favorite / send Interest, and
   see who liked or viewed them (per plan; favourites stay private).
3. Accepted matches can **chat in real time** — typing, online status, read receipts, no refresh.
4. Every important event produces a **real-time notification** (bell + toast), plus email/SMS per
   the member's preferences.
5. A member can upgrade via **Razorpay**, and entitlements (interests, contact views, chat) are
   enforced server-side.
6. A member can verify their ID (Aadhaar / Passport / DL / Voter ID) and get a **Verified badge**.
7. A KYC-verified broker can refer members, create and manage client profiles — one at a time or
   **in bulk from an Excel/CSV file** — work with **staff accounts** (manager, data entry,
   telecaller), export bureau-branded biodata PDFs, and earn commission.
8. Staff can run the whole business from the admin panel: members, moderation, verification,
   chat safety, reports, payments, refunds, brokers & payouts, content, master data, campaigns,
   settings, audit.

---

## 2. Vision, Personas & Scope

### 2.1 Vision
"The most trusted way for a Malayali family to find a match — verified people, private by default,
and conversations that happen instantly."

### 2.2 Personas

| Persona | Description | Key needs |
|---|---|---|
| **Anjali, 26, Kochi — self-registering bride** | IT professional, mobile-first | Fast search, privacy for photos/phone, real-time chat, verified profiles only |
| **Thomas, 58, Thrissur — parent managing son's profile** | Registers "for my son" | Simple wizard, family details, horoscope/star info, WhatsApp-able biodata |
| **Reji — broker in Thrissur** | Runs a small bureau with 3 staff, has 150 clients in an Excel register | Bulk-import his register, give staff their own logins, export biodata PDFs, track commission |
| **Sini — Reji's telecaller** | Calls client families and follows up on interests | "My clients" list, accept/decline interests for clients, follow-up reminders — no access to money or exports |
| **Divya — moderator** | Reviews new profiles/photos daily | Fast queue, keyboard shortcuts, clear reasons |
| **Anand — operations admin** | Runs the business | KPIs, revenue, broker payouts, abuse handling |
| **Rahul, 31, Dubai — NRI groom** | Lives abroad | Country/NRI filters, time-zone-agnostic chat, verified contact |

### 2.3 In scope for v1
Everything in modules **M01–M16** and **A01–A15**, including real-time chat and notifications,
Likes/Favorites/Interests, reactive search, Razorpay payments, ID verification, broker portal
(referral + managed profiles + exports), and the full admin panel.

### 2.4 Out of scope for v1 (roadmap — see §3.3)
Native mobile apps (the site is a responsive PWA-ready web app in v1), in-app voice/video calls,
automated horoscope (porutham / guna) matching, multi-language UI (Malayalam), AI photo
verification (selfie liveness), assisted matchmaking service desk. *(Broker bulk upload and broker
staff accounts, deferred in v4, are **in v1** — §11A.)*

---

## 3. Competitor Research

### 3.1 Platforms studied

| Platform | Positioning | Notable features |
|---|---|---|
| **KeralaMatrimony** (Matrimony.com / BharatMatrimony group) | #1 Malayali matrimony, huge pool | Chat, voice & video calling for premium; "Prime" government-ID-verified profiles; featured listing for premium; full profile incl. education institute, company, horoscope; **Assisted Service** with a Relationship Manager who shortlists and contacts prospects; mobile verification; ignore & block lists; photo/horoscope password protection; members are not told when you view them |
| **Shaadi.com** | Largest pan-India/NRI | Government-ID verified badge; **Shaadi Meet** video/voice calling (premium initiates, free can receive); privacy settings for who can call and when; option to hide phone number; premium = view verified contacts, direct messages, higher visibility, priority placement; Premium Assist; free kundali (36-guna) matching tool |
| **Jeevansathi** (Info Edge) | North-India heavy | **Contact filters** — only people matching your partner criteria can contact you; photo visibility options (all / only those I contact / only those I accept); astro-compatibility report from horoscope; profile format that prevents identification until you choose |
| **M4Marry** | South-Indian, human-led matchmaking | Mandatory mobile verification; multiple tiers (Premium, Premium Plus, Active Plus, Royale); add-ons like **Prime** and **Profile Highlighter**; advanced search, personalised recommendations |
| **Chavara Matrimony** | Kerala Christian community, 32 offline branches | Deep denomination filters (Syrian Catholic, Latin Catholic, Malankara, Jacobite, Orthodox, Marthoma, CSI…); strong offline branch network — validates Oppam's **branches + broker** model |

Market observation from reviews: users resent "pay before you know if there are matches" — free
members should get **enough value to judge the pool** (search, likes, receiving interests, reading
chats) before paying. This PRD's Free tier is designed with that in mind.

### 3.2 Feature matrix — what Oppam ships

Legend: ✅ v1 · 🟡 v1.5 (next 3 months after launch) · 🔵 v2 (roadmap) · — not planned

| Feature | Kerala/Bharat | Shaadi | Jeevansathi | M4Marry | **Oppam** |
|---|---|---|---|---|---|
| Mobile OTP registration | ✓ | ✓ | ✓ | ✓ | ✅ M01 |
| "Profile created by" (self/parent/sibling/relative) | ✓ | ✓ | ✓ | ✓ | ✅ M01 |
| Multi-step profile wizard + completeness meter | ✓ | ✓ | ✓ | ✓ | ✅ M02 |
| Moderation before profile goes live | ✓ | ✓ | ✓ | ✓ | ✅ A04 |
| Government ID verified badge | ✓ (Prime) | ✓ | ✓ | ✓ | ✅ M09 / A05 |
| Advanced search (religion, caste, star, education, job, income, location…) | ✓ | ✓ | ✓ | ✓ | ✅ M04 |
| Search by profile ID | ✓ | ✓ | ✓ | ✓ | ✅ M04 |
| Saved searches + new-match alerts | ✓ | ✓ | ✓ | – | ✅ M04 |
| Daily recommendations | ✓ | ✓ | ✓ | ✓ | ✅ M05 |
| Match score / "matches X of your preferences" | ✓ | ✓ | ✓ | – | ✅ M05 |
| Like / quick interest | – | ✓ (Connect) | ✓ | – | ✅ M06 |
| Favorite / shortlist | ✓ | ✓ | ✓ | ✓ | ✅ M06 |
| Formal interest (accept/decline) | ✓ | ✓ | ✓ | ✓ | ✅ M06 |
| Ignore list / Block / Report | ✓ | ✓ | ✓ | ✓ | ✅ M09 |
| Real-time chat | ✓ | ✓ | ✓ | ✓ | ✅ M07 |
| Typing, online status, read receipts | ✓ | ✓ | ✓ | – | ✅ M07 |
| Real-time notifications | ✓ | ✓ | ✓ | ✓ | ✅ M08 |
| Who viewed my profile | ✓ | ✓ | ✓ | ✓ | ✅ M15 (Gold+) |
| Who liked / favourited me | – | ✓ | ✓ | – | ✅ M15 |
| Photo privacy (all / on request / after accept) | ✓ (password) | ✓ | ✓ | ✓ | ✅ M11 |
| Phone privacy (hide / show to premium / after accept) | ✓ | ✓ | ✓ | ✓ | ✅ M14 |
| Contact filters (only people matching my criteria can contact me) | – | ✓ | ✓ | – | ✅ M14 |
| Photo request / horoscope request | ✓ | ✓ | ✓ | – | ✅ M06 |
| Profile highlight / boost add-on | ✓ | ✓ | – | ✓ | ✅ M10 (Diamond) · 🟡 paid add-on |
| Horoscope upload (PDF/image) | ✓ | ✓ | ✓ | ✓ | ✅ M02 |
| Star/rasi-based porutham auto-matching | ✓ | ✓ (guna) | ✓ | ✓ | 🔵 v2 |
| Voice / video call (masked) | ✓ | ✓ | ✓ | – | 🔵 v2 |
| Assisted service (relationship manager) | ✓ | ✓ | ✓ | ✓ | 🔵 v2 (reuses broker-managed profile model) |
| Success stories | ✓ | ✓ | ✓ | ✓ | ✅ M12 |
| Offline branches | ✓ | – | – | ✓ | ✅ M12 |
| Broker / franchise portal | (franchise) | – | – | – | ✅ **M13 — Oppam differentiator** |
| Broker staff accounts (manager / data entry / telecaller) | (franchise staff) | – | – | – | ✅ §11A B.8 |
| Broker bulk profile upload (Excel/CSV + photo zip) | (franchise bulk add) | – | – | – | ✅ §11A B.9 |
| Biodata PDF export | – | – | – | – | ✅ M13 (broker) · 🟡 member self-download |
| Malayalam UI | ✓ | – | – | ✓ | 🔵 v2 |
| Native apps | ✓ | ✓ | ✓ | ✓ | 🔵 v2 (PWA in v1) |
| Selfie / liveness photo verification | – | ✓ | – | – | 🔵 v2 |
| Incognito / hide profile temporarily | ✓ | ✓ | ✓ | – | ✅ M14 |
| Profile delete with reason ("got married on Oppam" → story prompt) | ✓ | ✓ | ✓ | ✓ | ✅ M14 |

### 3.3 Roadmap (post-v1)
- **v1.5:** Profile Boost as a paid add-on; member self-download of own biodata PDF; WhatsApp
  notifications (via approved templates); PWA install + web push; saved-search email digest tuning.
- **v2:** 10-porutham star matching + horoscope compatibility report; masked voice/video calls
  (WebRTC, premium initiates, free receives); Malayalam UI; Assisted Service (relationship-manager
  role built on the broker-managed-profile model); native apps (reuse the same Laravel backend via a
  Sanctum API layer); selfie liveness; broker multi-branch bureaus (one Owner, several offices)
  and custom staff roles beyond the four v1 presets.

---

## 4. Tech Stack

| Layer | Choice | Notes |
|---|---|---|
| Language / framework | **PHP 8.3, Laravel 12** | One application for member site, broker portal, admin panel |
| Interactivity | **Livewire 3** + **Alpine.js** (bundled with Livewire) | Full-page Livewire components for pages, nested components for widgets; `wire:navigate` for SPA-style navigation |
| Real-time | **Laravel Reverb** (Pusher-protocol WebSocket server) | Swappable to **Pusher Channels** by `.env` only |
| Client real-time | **Laravel Echo** + **pusher-js** | Echo listeners wired straight into Livewire via `#[On('echo-private:…')]` |
| CSS / UI | The template's **Bootstrap 5.3.2 + `style.css` + `responsive.css`** design tokens | Converted as-is to Vite assets. Swiper 11 for carousels. Font Awesome upgraded 4.7 → 6 (tracked task) |
| Build | **Vite** (`laravel-vite-plugin`) | Replaces CDN tags and the `?v=filemtime` cache-buster |
| Database | **MySQL 8.0** (InnoDB, utf8mb4) | ULID primary keys, money in paise |
| Cache / queue / sessions / presence | **Redis 7** | `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `CACHE_STORE=redis` |
| Queue monitor | **Laravel Horizon** | Supervisors for `default`, `broadcasts`, `notifications`, `mail`, `media`, `matching`, `imports` |
| Search | MySQL composite indexes + query builder in v1; **Laravel Scout + Meilisearch** from 50k active profiles | Search is behind a `ProfileSearch` service so the engine swap is local |
| Auth | Laravel session auth (`web` guard for members & brokers, `admin` guard for staff), **Laravel Fortify** (2FA for admins), mobile OTP via SMS | |
| Roles/permissions | **spatie/laravel-permission** | Admin RBAC, same keys as v4 |
| Media | **spatie/laravel-medialibrary** + **Intervention Image** | Photo conversions (thumb 200, card 600, full 1200, WebP), blurred variant for privacy |
| Spreadsheets | **maatwebsite/laravel-excel** (PhpSpreadsheet) | Broker bulk import (chunked, queued) and template/error-report generation |
| PDF | **barryvdh/laravel-dompdf** (or spatie/browsershot if richer layout needed) | Biodata PDFs, invoices |
| Payments | **Razorpay** (orders + webhooks) | Hosted checkout; no card fields on our pages |
| SMS / OTP | **MSG91** (DLT-registered templates) | Behind an `SmsGateway` interface |
| Email | Amazon SES / Mailgun via Laravel Mail, queued | |
| Storage | S3-compatible: `public` bucket (approved photos via CDN), **private** bucket (ID documents, originals) | ID docs never on the public disk |
| Monitoring | Laravel Pulse, Sentry, Telescope (non-prod only) | |
| Testing | **Pest** + Livewire test helpers, Laravel Dusk for E2E chat/notification flows | |

---

## 5. System Architecture

### 5.1 Topology

```
                    ┌──────────────────────────── Browser ─────────────────────────────┐
                    │  Blade HTML + Livewire 3 (wire:model, wire:navigate)             │
                    │  Alpine.js (typing, toasts, emoji, scroll)                       │
                    │  Laravel Echo + pusher-js  ── one WebSocket per tab ──┐          │
                    └──────────────┬───────────────────────────────────────┼──────────┘
                           HTTPS (Livewire XHR)                      WSS (Pusher protocol)
                                   │                                       │
                         ┌─────────▼─────────┐                   ┌─────────▼──────────┐
                         │  Nginx            │                   │  Nginx /ws proxy   │
                         └─────────┬─────────┘                   └─────────┬──────────┘
                                   │                                       │
      ┌────────────────────────────▼───────────────┐          ┌────────────▼────────────┐
      │ Laravel 12 app (PHP-FPM, N instances)       │  HTTP    │ Laravel Reverb server   │
      │  • Member site  (oppam.in)                  │ publish  │ (php artisan reverb:start│
      │  • Broker portal (oppam.in/broker)          ├─────────►│  supervisor-managed,     │
      │  • Admin panel  (admin.oppam.in)            │          │  horizontally scaled via │
      │  • /broadcasting/auth (channel auth)        │◄─────────┤  Redis pub/sub)          │
      └───┬───────────────┬──────────────┬─────────┘  auth    └─────────────────────────┘
          │               │              │
   ┌──────▼─────┐  ┌──────▼─────┐  ┌─────▼──────────────────┐
   │ MySQL 8    │  │ Redis 7    │  │ Horizon queue workers   │── Razorpay, MSG91, SES, S3
   │ (primary + │  │ cache,     │  │ broadcasts, notifications│
   │  replica)  │  │ sessions,  │  │ mail, media, matching    │
   └────────────┘  │ queues     │  └─────────────────────────┘
                   └────────────┘   Scheduler (cron): daily matches, expiries, digests
```

### 5.2 Why a Livewire monolith
- The template is already server-rendered PHP with partials; each partial becomes a Blade
  component almost 1:1.
- Livewire gives reactive UI (search filters, chat, likes) **without a separate API or JS
  framework**; business logic stays in PHP Actions, testable in isolation.
- Reverb is first-party, speaks the Pusher protocol, and integrates with Livewire's
  `#[On('echo…')]` listeners, so real-time needs no custom JS beyond Echo configuration.
- One deployment, one auth system, CSRF handled automatically.

### 5.3 Code layering (unchanged principle from v4 §4a)

| Layer | Folder | Rule |
|---|---|---|
| Presentation | `app/Livewire/{Member,Broker,Admin}/*`, `resources/views/**` | No business logic. Calls Actions, renders state. |
| Application | `app/Actions/**` (e.g. `Interests\SendInterest`, `Chat\SendMessage`) | One use-case per class; transactions, authorization, events |
| Domain | `app/Models/**`, `app/Enums/**`, `app/Policies/**`, `app/Events/**` | Eloquent models, enums, policies, broadcast events |
| Infrastructure | `app/Services/**` (`SmsGateway`, `RazorpayGateway`, `ProfileSearch`, `BiodataPdfRenderer`) | External integrations behind interfaces |

### 5.4 Repository structure (abridged)

```
oppam/
├─ app/
│  ├─ Actions/{Auth,Profile,Search,Matches,Engagement,Chat,Notifications,Verification,
│  │           Billing,Media,Broker,Admin,Safety}/
│  ├─ Enums/                 ProfileStatus, InterestStatus, MessageStatus, PlanCode, ...
│  ├─ Events/                MessageSent, MessageRead, ConversationUpdated, ProfileLiked, ...
│  ├─ Livewire/
│  │  ├─ Member/             Dashboard, Search, ProfileShow, Chat/Inbox, Chat/Thread, ...
│  │  ├─ Broker/             Dashboard, ManagedProfiles, ManagedProfileForm, Payouts, ...
│  │  ├─ Admin/              Members, Moderation, Verification, Chat, Billing, Brokers, ...
│  │  └─ Shared/             NotificationBell, ToastStack, OnlineDot, LikeButton, FavoriteButton
│  ├─ Models/
│  ├─ Notifications/         InterestReceived, InterestAccepted, NewMessage, ProfileLiked, ...
│  ├─ Policies/
│  └─ Services/
├─ resources/
│  ├─ views/
│  │  ├─ components/         profile-row, profile-tile, member-card, story-card, pricing-card
│  │  ├─ layouts/            public, member, broker, admin
│  │  └─ livewire/{member,broker,admin,shared}/
│  ├─ css/                   style.css, responsive.css (from template), admin.css
│  └─ js/                    app.js, echo.js, chat.js (Alpine helpers)
├─ routes/                   web.php, broker.php, admin.php, channels.php, console.php
├─ database/{migrations,seeders,factories}/
└─ tests/{Unit,Feature,Browser}/
```

---

## 6. Template → Laravel Conversion Map

Every template page becomes a route + a Blade view or full-page Livewire component. Template
includes become Blade layouts/components. Hardcoded demo arrays become Eloquent queries.

### 6.1 Includes → layouts & components

| Template file | Laravel equivalent |
|---|---|
| `assets/includes/auth.php` (`is_logged_in()`, `csrf_field()`, `e()/ee()`, `url()`) | Laravel `auth()`, `@auth/@guest`, `@csrf` (automatic in Livewire), `{{ }}` escaping, `route()` |
| `head.php`, `links.php` | `layouts/partials/head.blade.php` with `@vite`, per-page `@section('title')`, SEO via `$seo` view data; `SITE_LIVE` → `config('app.indexable')` |
| `header.php` (public vs member nav, preloader, drawer) | `layouts/partials/header.blade.php` with `@auth` branch; bell becomes `<livewire:shared.notification-bell />`; unread chat badge `<livewire:shared.chat-badge />` |
| `pagenav.php` + `page_nav_map()` | `<x-page-nav>` component reading a `config/pagenav.php` map keyed by route name |
| `footer.php`, `footer2.php` (mobile tab bar) | `layouts/partials/footer.blade.php`, `<x-mobile-tabbar>` (with live badges) |
| `script.php`, `custom.js` | `resources/js/app.js` (Vite) — preloader, drawer, Swiper init, back button, Echo bootstrap |
| `profile-row.php`, `profile-tile.php`, `member-card.php`, `story-card.php`, `pricing-cards.php`, `pagination.php`, `ads.php` | Blade components `<x-profile-row :profile>` etc.; action buttons inside them become nested Livewire components (`<livewire:shared.like-button :profile-id>`) |
| `profiles-data.php`, `plans.php`, `stories-data.php` | `Profile`, `Plan`, `SuccessStory` models |
| `.htaccess` extensionless URLs | Laravel named routes; 301 redirects from legacy `*.php` URLs kept in `routes/legacy.php` |

### 6.2 Pages → routes & components

| Template page | Route (name) | Implementation | Module |
|---|---|---|---|
| `index.php` | `/` (`home`) | Blade + `<livewire:public.quick-register />` hero form | M12, M01 |
| `login.php` | `/login` | `Member\Auth\Login` (password or OTP) | M01 |
| `register.php` | `/register` | `Member\Auth\Register` (+ OTP step) | M01 |
| `forgot-password.php` | `/forgot-password` | `Member\Auth\ForgotPassword` (OTP or email link) | M01 |
| `profile-creation.php` → `profile-photos.php` (6 pages) | `/onboarding/{step}` | `Member\Onboarding\Wizard` — one component, 6 steps, autosave per step | M02 |
| `dashboard.php` | `/dashboard` | `Member\Dashboard` (lazy sliders) | M05 |
| `my-profile.php` | `/me` | `Member\Profile\MyProfile` (+ inline edit modals) | M03 |
| `single-profile.php` | `/profile/{code}` | `Member\Profile\Show` | M03 |
| `all-profiles.php` | `/profiles` | `Member\Browse\AllProfiles` | M04 |
| `search.php` | `/search` | `Member\Search\Search` (reactive filters, `#[Url]`) | M04 |
| `my-matches.php` | `/matches` | `Member\Matches\MyMatches` (tabs: Latest, Mutual, Yet to view, Viewed, Near me) | M05 |
| `daily-matches.php` | `/matches/daily` | `Member\Matches\Daily` | M05 |
| `interest.php` | `/interests` | `Member\Engagement\Interests` (tabs: Received, Sent, Accepted, Declined) | M06 |
| *(new)* | `/likes` | `Member\Engagement\Likes` (Liked me / I liked / Mutual) | M06 |
| *(new)* | `/favorites` | `Member\Engagement\Favorites` | M06 |
| *(new)* | `/visitors` | `Member\Activity\Visitors` (who viewed me) | M15 |
| `messages.php` | `/messages`, `/messages/{conversation}` | `Member\Chat\Inbox` + `Member\Chat\Thread` | M07 |
| `notifications.php` | `/notifications` | `Member\Notifications\Index` (infinite scroll) | M08 |
| `verification.php` | `/verify` | `Member\Verification\Upload` | M09 |
| *(new)* | `/settings/{section}` | `Member\Settings\*` (account, privacy, notifications, blocked, delete) | M14 |
| `package.php` | `/plans` | Blade + `<x-pricing-card>` | M10 |
| `checkout.php` | `/checkout/{plan}` | `Member\Billing\Checkout` (Razorpay) | M10 |
| *(new)* | `/billing` | `Member\Billing\History` (orders, invoices) | M10 |
| `about`, `branches`, `success-stories`, `contact`, `privacy`, `terms`, `faq`, `404` | `/about` … | Blade from CMS tables; contact → `Public\ContactForm` | M12 |
| *(new)* broker portal | `/broker/*` | `Broker\*` components | M13 |

Template fixes to carry into the conversion (from template `CLAUDE.md` and the v5 analysis):
remove leftover "VishwakarmaMatrimony" text in the dashboard sidebar; restore login/register
anchors to `<button type="submit">`; caste list becomes religion-dependent; the hardcoded "3"
unread count in both bells becomes live; keep the accessibility rules (one `<h1>`, labelled
controls, skip link, `<main id="main">`, no nested anchors) and the 3/6/3 dashboard layout.

---
## 7. Data Model — MySQL 8 via Eloquent

Conventions (unchanged from v4): **ULID** primary keys (`$t->ulid('id')->primary()`), public
human-readable codes separate from keys (`profiles.code = OPM12370`, `brokers.code = BRK1042` — the
URL uses the code, never the ULID), money in **paise** (unsigned integers), `timestamps()` on every
table, `softDeletes()` on member-owned data, enums as PHP backed enums stored as strings.

### 7.1 Table inventory

| Domain | Tables |
|---|---|
| Identity | `users`, `otp_challenges`, `password_reset_tokens`, `sessions`, `login_events`, `devices` (web-push subscriptions, v1.5) |
| Profile | `profiles`, `education_careers`, `family_details`, `partner_preferences`, `contact_details`, `horoscope_details`, `lifestyle_details`, `profile_completeness` (cached score) |
| Media | `media` (spatie), `photo_requests` |
| Privacy & settings | `privacy_settings`, `notification_preferences`, `contact_filters` |
| Engagement | **`likes`**, **`favorites`**, `interests`, `interest_events`, `profile_views`, `ignores`, `blocks`, `contact_views` |
| Matching & search | `daily_matches`, `saved_searches`, `match_scores` (cache) |
| Chat | `conversations`, `conversation_participants`, `messages`, `message_attachments` (v1: images only), `message_flags` |
| Notifications | `notifications` (Laravel database channel), `outbound_messages` (SMS/email log), `campaigns`, `campaign_recipients`, `announcements` |
| Verification & safety | `verification_requests`, `verification_documents`, `abuse_reports`, `safety_actions` |
| Billing | `plans`, `plan_features`, `subscriptions`, `entitlement_usages`, `addons`, `orders`, `payments`, `refunds`, `invoices`, `coupons`, `coupon_redemptions` |
| Broker | `brokers`, `broker_kyc`, `broker_referrals`, `broker_payout_runs`, `broker_payout_items`, `broker_profile_exports`; `profiles.owner_type`, `profiles.managed_by_broker_id`, `profiles.broker_consent_confirmed_at`, `profiles.claimed_at` (v4 §6.11a, unchanged); **v5:** `broker_staff`, `broker_staff_invitations`, `broker_import_batches`, `broker_import_rows`, `broker_client_notes`; `profiles.created_by_user_id`, `profiles.assigned_staff_id`, `profiles.import_batch_id`, `profiles.consent_confirmed_by_user_id`; `broker_profile_exports.user_id` |
| Content & master data | `success_stories`, `branches`, `faq_items`, `pages` (about/privacy/terms), `banners`, `testimonials`, `contact_submissions`, `master_religions`, `master_castes` (parent religion), `master_stars`, `master_rasis`, `master_districts`, `master_states`, `master_countries`, `master_education`, `master_occupations`, `master_income_bands`, `master_mother_tongues`, `master_options` (generic enums: diet, complexion, body type…) |
| Admin & audit | `admin_users`, `roles`, `permissions`, `model_has_roles`, `role_has_permissions`, `audit_logs`, `support_tickets`, `support_ticket_messages`, `settings` (key/value), `feature_flags` |

### 7.2 Key tables (DDL)

```php
Schema::create('users', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->string('phone', 16)->unique();                 // E.164, primary login id
    $t->string('email')->nullable()->unique();
    $t->string('password');
    $t->enum('role', ['MEMBER', 'BROKER'])->default('MEMBER');
    $t->enum('created_for', ['SELF','SON','DAUGHTER','BROTHER','SISTER','RELATIVE','FRIEND']);
    $t->timestamp('phone_verified_at')->nullable();
    $t->timestamp('email_verified_at')->nullable();
    $t->timestamp('last_seen_at')->nullable();         // updated by presence (§9.6)
    $t->enum('status', ['ACTIVE','SUSPENDED','BANNED','DELETED'])->default('ACTIVE');
    $t->rememberToken();
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('profiles', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('user_id')->nullable()->unique()->constrained();   // nullable: broker-managed (v4)
    $t->string('code', 12)->unique();                  // OPM12370 — shown & used in URLs
    $t->enum('gender', ['MALE','FEMALE']);
    $t->string('first_name'); $t->string('last_name');
    $t->date('dob');
    $t->unsignedSmallInteger('height_cm');
    $t->unsignedSmallInteger('weight_kg')->nullable();
    $t->enum('marital_status', ['NEVER_MARRIED','DIVORCED','WIDOWED','AWAITING_DIVORCE','ANNULLED']);
    $t->unsignedTinyInteger('children_count')->default(0);
    $t->enum('physical_status', ['NORMAL','PHYSICALLY_CHALLENGED'])->default('NORMAL');
    $t->foreignId('religion_id')->constrained('master_religions');
    $t->foreignId('caste_id')->nullable()->constrained('master_castes');
    $t->boolean('caste_no_bar')->default(false);
    $t->foreignId('mother_tongue_id')->constrained('master_mother_tongues');
    $t->foreignId('star_id')->nullable()->constrained('master_stars');
    $t->foreignId('rasi_id')->nullable()->constrained('master_rasis');
    $t->foreignId('district_id')->nullable()->constrained('master_districts');
    $t->string('about', 1000)->nullable();
    $t->enum('status', ['DRAFT','PENDING_REVIEW','ACTIVE','REJECTED','HIDDEN','SUSPENDED','DELETED'])
      ->default('DRAFT');
    $t->boolean('is_verified')->default(false);        // ID verified badge (M09)
    $t->boolean('is_premium')->default(false);         // denormalised from subscriptions
    $t->timestamp('highlighted_until')->nullable();    // boost / featured
    $t->unsignedTinyInteger('completeness')->default(0);
    // Broker (v4 §6.11 / §6.11a — unchanged)
    $t->foreignUlid('broker_id')->nullable()->constrained('brokers');      // referral attribution
    $t->string('broker_code_raw', 20)->nullable();
    $t->boolean('broker_verified')->default(false);
    $t->enum('owner_type', ['SELF','BROKER_MANAGED'])->default('SELF');
    $t->foreignUlid('managed_by_broker_id')->nullable()->constrained('brokers');
    $t->timestamp('broker_consent_confirmed_at')->nullable();
    $t->timestamp('claimed_at')->nullable();
    $t->timestamp('published_at')->nullable();
    $t->timestamp('last_active_at')->nullable();
    $t->timestamps(); $t->softDeletes();

    $t->index(['status', 'gender', 'dob']);            // primary search index
    $t->index(['status', 'gender', 'religion_id', 'caste_id']);
    $t->index(['status', 'gender', 'district_id']);
    $t->index(['managed_by_broker_id', 'status']);
});

// ── Engagement: Likes, Favorites, Interests ─────────────────────────────
Schema::create('likes', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('from_profile_id')->constrained('profiles')->cascadeOnDelete();
    $t->foreignUlid('to_profile_id')->constrained('profiles')->cascadeOnDelete();
    $t->timestamp('seen_at')->nullable();              // recipient has seen it in "Liked me"
    $t->timestamps();
    $t->unique(['from_profile_id', 'to_profile_id']);
    $t->index(['to_profile_id', 'created_at']);
});

Schema::create('favorites', function (Blueprint $t) {       // private — never notified
    $t->ulid('id')->primary();
    $t->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();        // owner
    $t->foreignUlid('favorite_profile_id')->constrained('profiles')->cascadeOnDelete();
    $t->string('note', 200)->nullable();               // private note ("met family on 12 Oct")
    $t->timestamps();
    $t->unique(['profile_id', 'favorite_profile_id']);
});

Schema::create('interests', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('from_profile_id')->constrained('profiles');
    $t->foreignUlid('to_profile_id')->constrained('profiles');
    $t->enum('status', ['PENDING','ACCEPTED','DECLINED','WITHDRAWN','EXPIRED'])->default('PENDING');
    $t->string('message', 300)->nullable();            // optional templated note
    $t->timestamp('responded_at')->nullable();
    $t->timestamp('expires_at');                       // 30 days after send
    $t->timestamps();
    $t->unique(['from_profile_id', 'to_profile_id']);  // one live pair per direction
    $t->index(['to_profile_id', 'status', 'created_at']);
    $t->index(['from_profile_id', 'status', 'created_at']);
});

Schema::create('profile_views', function (Blueprint $t) {
    $t->id();
    $t->foreignUlid('viewer_profile_id')->constrained('profiles')->cascadeOnDelete();
    $t->foreignUlid('viewed_profile_id')->constrained('profiles')->cascadeOnDelete();
    $t->date('view_date');                             // one row per viewer/viewed/day
    $t->unsignedSmallInteger('count')->default(1);
    $t->timestamps();
    $t->unique(['viewer_profile_id', 'viewed_profile_id', 'view_date']);
    $t->index(['viewed_profile_id', 'updated_at']);
});

Schema::create('blocks', function (Blueprint $t) {           // symmetric hide, silent
    $t->ulid('id')->primary();
    $t->foreignUlid('blocker_profile_id')->constrained('profiles');
    $t->foreignUlid('blocked_profile_id')->constrained('profiles');
    $t->string('reason', 50)->nullable();
    $t->timestamps();
    $t->unique(['blocker_profile_id', 'blocked_profile_id']);
});
// `ignores` has the same shape as `blocks` but is one-directional: hides the ignored profile
// from MY lists only; they can still see and contact me.

// ── Chat ────────────────────────────────────────────────────────────────
Schema::create('conversations', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('interest_id')->nullable()->constrained();   // the accepted interest that opened it
    $t->string('pair_key', 60)->unique();              // sorted "profileA|profileB" — one thread per pair
    $t->foreignUlid('last_message_id')->nullable();
    $t->timestamp('last_message_at')->nullable()->index();
    $t->enum('status', ['OPEN','FROZEN','CLOSED'])->default('OPEN');  // FROZEN by block/moderation
    $t->timestamps();
});

Schema::create('conversation_participants', function (Blueprint $t) {
    $t->id();
    $t->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
    $t->foreignUlid('profile_id')->constrained('profiles');
    $t->foreignUlid('last_read_message_id')->nullable();
    $t->unsignedInteger('unread_count')->default(0);   // denormalised for badges
    $t->boolean('is_muted')->default(false);
    $t->boolean('is_archived')->default(false);
    $t->timestamps();
    $t->unique(['conversation_id', 'profile_id']);
    $t->index(['profile_id', 'is_archived']);
});

Schema::create('messages', function (Blueprint $t) {
    $t->ulid('id')->primary();                         // ULID = time-sortable, used as cursor
    $t->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
    $t->foreignUlid('sender_profile_id')->constrained('profiles');
    $t->uuid('client_id');                             // optimistic-UI de-dupe key
    $t->enum('type', ['TEXT','IMAGE','SYSTEM'])->default('TEXT');
    $t->text('body')->nullable();
    $t->enum('status', ['SENT','DELIVERED','READ'])->default('SENT');
    $t->timestamp('delivered_at')->nullable();
    $t->timestamp('read_at')->nullable();
    $t->boolean('is_flagged')->default(false);         // content-safety hit (A13)
    $t->timestamp('deleted_for_all_at')->nullable();   // sender unsend within 60 min
    $t->timestamps();
    $t->unique(['conversation_id', 'client_id']);
    $t->index(['conversation_id', 'id']);
});

// ── Notifications (Laravel standard table) ──────────────────────────────
// php artisan make:notifications-table  → id (uuid), type, notifiable_type, notifiable_id,
// data (json), read_at, timestamps. Plus an index on (notifiable_id, read_at, created_at).

Schema::create('notification_preferences', function (Blueprint $t) {
    $t->id();
    $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
    $t->string('event', 40);                           // interest_received, new_message, ...
    $t->boolean('in_app')->default(true);              // always true for safety events
    $t->boolean('email')->default(true);
    $t->boolean('sms')->default(false);
    $t->boolean('push')->default(false);               // v1.5
    $t->unique(['user_id', 'event']);
});

Schema::create('privacy_settings', function (Blueprint $t) {
    $t->foreignUlid('profile_id')->primary()->constrained('profiles')->cascadeOnDelete();
    $t->enum('photo_visibility', ['ALL_MEMBERS','PREMIUM_ONLY','ON_REQUEST','ACCEPTED_ONLY'])->default('ALL_MEMBERS');
    $t->enum('phone_visibility', ['PREMIUM_ONLY','ACCEPTED_ONLY','HIDDEN'])->default('PREMIUM_ONLY');
    $t->enum('horoscope_visibility', ['ALL_MEMBERS','ACCEPTED_ONLY','ON_REQUEST'])->default('ALL_MEMBERS');
    $t->boolean('show_online_status')->default(true);
    $t->boolean('show_last_seen')->default(true);
    $t->boolean('read_receipts')->default(true);
    $t->boolean('incognito')->default(false);          // hidden from search; visible to existing connections
    $t->boolean('contact_filter_enabled')->default(false);  // only partner-pref matches may contact me
    $t->timestamps();
});

Schema::create('saved_searches', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
    $t->string('name', 60);
    $t->json('filters');                               // the same shape as the Search component state
    $t->enum('alert_frequency', ['OFF','DAILY','WEEKLY'])->default('DAILY');
    $t->timestamp('last_alerted_at')->nullable();
    $t->timestamps();
});
```

All broker tables (base, v4 addendum and v5 staff/import tables) are defined in **§11A — B.13**.

Wizard-step tables (`education_careers`, `family_details`, `partner_preferences`,
`contact_details`, `horoscope_details`), verification, billing, broker, content and admin tables are
**as defined in v4 §6.5–§6.14** and not repeated here. New columns added in v5:
`lifestyle_details` (diet, smoking, drinking, complexion, body type, hobbies json),
`partner_preferences.mother_tongue_ids` (json), `partner_preferences.star_ids` (json),
`partner_preferences.country_ids` (json).

### 7.3 Entitlements (from v4 §16.14, extended for Likes/Favorites/Chat)

| Entitlement | Free | Silver ₹499/m | Gold ₹999/m ⭐ | Diamond ₹1,999/m |
|---|---|---|---|---|
| Search, view profiles, daily matches | ✅ | ✅ | ✅ | ✅ |
| **Likes per day** | 10 | 50 | Unlimited | Unlimited |
| **Favorites** (total) | 25 | 200 | Unlimited | Unlimited |
| See **who liked me** | Count + blurred list | ✅ | ✅ | ✅ |
| Send Interests / month | 3 | 25 | 100 | Unlimited |
| Receive & accept interests | ✅ | ✅ | ✅ | ✅ |
| **Chat** — read messages | ✅ | ✅ | ✅ | ✅ |
| **Chat** — send messages (accepted matches only) | ❌ (read-only; one free reply per accepted conversation — see R-M07-3) | ✅ | ✅ | ✅ |
| View verified phone numbers / month | 0 | 10 | 50 | Unlimited |
| See **who viewed me** | Count only | Count only | ✅ | ✅ |
| Profile highlighted in search | ❌ | ❌ | ✅ | ✅ + top placement |
| Relationship manager (v2 Assisted) | ❌ | ❌ | ❌ | ✅ (v2) |

All limits live in `plan_features` (editable in A06), cached per user, and are enforced in the
Action layer — never only in the UI. Counters reset on the subscription anniversary (monthly
limits) or at 00:00 IST (daily limits).

---

## 8. Authentication, Roles & Permissions

### 8.1 Guards

| Guard | Users | Mechanism | Where |
|---|---|---|---|
| `web` | Members **and** brokers (`users.role`) | Laravel session (Redis), remember-me 30 days | `oppam.in`, `oppam.in/broker` |
| `admin` | Staff (`admin_users`) | Separate session cookie, **mandatory TOTP 2FA** (Fortify), optional IP allowlist | `admin.oppam.in` |

Brokers **and their staff** are `users` with `role = BROKER` plus a `broker_staff` row naming the
bureau and the staff role (`OWNER` for the KYC'd broker, or `MANAGER` / `DATA_ENTRY` /
`TELECALLER`). The `/broker` route group uses `auth` + `role:BROKER` + `broker.active` +
`broker.staff.active` middleware, and every route/component declares the bureau permission it needs
(`$this->authorize('bureau', 'profiles.import')`, resolved by a `BureauGate` from the role ceiling
minus the Owner's switch-offs — §11A B.2). Owner and Manager must pass an OTP challenge on each new
device. A broker/staff login does not own a matrimony profile (anyone who also wants to marry
registers a separate member account with a different mobile number).

### 8.2 Member login methods
1. **Mobile + OTP** (primary) — 6-digit, 5-minute expiry, 3 attempts, 3 sends per 15 min per phone,
   10 per day per IP.
2. **Mobile/email/profile code + password** (secondary).
3. Forgot password via OTP to the registered mobile (or email link); identical responses whether or
   not the account exists.

### 8.3 Broadcasting authentication
`POST /broadcasting/auth` is registered with `Broadcast::routes(['middleware' => ['web','auth']])`
for members/brokers and separately `['web','auth:admin']` under the admin domain. Every private and
presence channel callback in `routes/channels.php` re-checks the database (§9.3) — a user never
joins a channel by knowing its name.

### 8.4 Admin RBAC
Same permission keys and seed roles as v4 §8.8 (`super_admin`, `moderator`,
`verification_officer`, `support`, `finance`, `content_editor`, `read_only`), plus new keys:
`chat.view_flagged`, `chat.freeze`, `chat.read_conversation` (break-glass, audited, reason
required), `campaigns.view`, `campaigns.send`, `settings.view`, `settings.edit`,
`notifications.broadcast`. Non-negotiables from v4 §8.9 remain: 2FA for every admin, time-boxed and
bannered impersonation, `verification.document.view` separate from `verification.approve`, every
admin write audited, super-admin cannot remove the last super-admin.

---

## 9. Real-Time Architecture (Reverb / Pusher + Echo + Livewire)

This section is the contract for everything "live": chat, typing, presence, likes, interests,
notifications, badges, and admin live queues.

### 9.1 Components

| Piece | Role |
|---|---|
| **Laravel Reverb** | WebSocket server, Pusher protocol. Runs as `php artisan reverb:start --host=0.0.0.0 --port=8080` under Supervisor, behind Nginx at `wss://ws.oppam.in`. Horizontal scaling via `REVERB_SCALING_ENABLED=true` (Redis pub/sub). |
| **Pusher Channels** (optional) | Drop-in managed alternative: set `BROADCAST_CONNECTION=pusher` + `PUSHER_*` keys and `VITE_BROADCAST_DRIVER=pusher`. No code change. |
| **Broadcast events** | PHP classes implementing `ShouldBroadcast` (queued on the `broadcasts` queue) or `ShouldBroadcastNow` for latency-critical chat events. Always `->toOthers()` where the sender's tab already rendered the change. |
| **Laravel Echo + pusher-js** | Client library, bootstrapped once in `resources/js/echo.js`. `wire:navigate` keeps the same page JS alive, so the socket survives page changes. |
| **Livewire listeners** | Components subscribe declaratively: `#[On('echo-private:chat.{conversationId},.message.sent')]`. Livewire joins/leaves the channel when the component mounts/unmounts. |
| **Alpine.js** | Pure-client concerns: typing whispers, scroll-to-bottom, optimistic bubble rendering, toast timers, sound. |

### 9.2 Echo bootstrap (`resources/js/echo.js`)

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: import.meta.env.VITE_BROADCAST_DRIVER ?? 'reverb',   // 'reverb' | 'pusher'
    key: import.meta.env.VITE_REVERB_APP_KEY ?? import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,                  // pusher only
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

### 9.3 Channel map (`routes/channels.php`)

| Channel | Type | Who may join (checked in DB on every subscribe) | Used for |
|---|---|---|---|
| `App.Models.User.{userId}` | private | `$user->id === $userId` | Laravel notifications (bell, toasts), like/interest/view signals, entitlement changes, forced logout |
| `chat.{conversationId}` | private | user's profile is a participant **and** conversation not `CLOSED` **and** no block between the pair | New messages, read receipts, message deletions |
| `inbox.{profileId}` | private | owns the profile | Conversation list re-ordering, unread counts across all threads |
| `presence.online` | presence | any active member with `show_online_status = true`; returns `{profile_code}` only | Online dots across the site |
| `presence-chat.{conversationId}` | presence | same as `chat.{id}` | "Online now" in thread header + **typing whispers** (client events) |
| `broker.{brokerId}` | private | the Owner and active staff of that bureau (earnings events are sent only to `broker-owner.{brokerId}`) | Moderation outcome of managed profiles, import progress (`.import.progress`: batch, processed, valid, error), follow-up reminders, staff deactivation |
| `broker-owner.{brokerId}` | private | the bureau's Owner only | Commission earned, payout status, seat/KYC changes |
| `admin.queues` | private (admin guard) | any admin with `moderation.view` or `verification.queue.view` | Live counts on moderation, verification, reports, flagged-chat queues |
| `admin.dashboard` | private (admin guard) | `dashboard.view` | Live KPI ticks (signups, payments today) |

```php
Broadcast::channel('chat.{conversation}', function (User $user, Conversation $conversation) {
    $profileId = $user->profile?->id;
    return $profileId
        && $conversation->status !== ConversationStatus::Closed
        && $conversation->participants()->where('profile_id', $profileId)->exists()
        && ! Block::betweenPairOf($conversation)->exists();
});

Broadcast::channel('presence-chat.{conversation}', function (User $user, Conversation $conversation) {
    if (! Gate::forUser($user)->allows('participate', $conversation)) return false;
    return ['code' => $user->profile->code];           // never phone/email/ULID
});
```

### 9.4 Event catalogue

| Event class | Channel(s) | Broadcast name | Payload (minimum necessary) | Queue |
|---|---|---|---|---|
| `MessageSent` | `chat.{c}`, `inbox.{recipient}` | `.message.sent` | `id, client_id, conversation_id, sender_code, type, body, created_at` | now |
| `MessagesDelivered` | `chat.{c}` | `.message.delivered` | `up_to_message_id` | now |
| `MessagesRead` | `chat.{c}` | `.message.read` | `reader_code, up_to_message_id, read_at` (only if reader has read receipts on) | now |
| `MessageDeleted` | `chat.{c}` | `.message.deleted` | `id` | now |
| `ConversationUpdated` | `inbox.{p}` | `.conversation.updated` | `conversation_id, last_message_preview, last_message_at, unread_count` | broadcasts |
| `ConversationFrozen` | `chat.{c}` | `.conversation.frozen` | `reason_code` | now |
| `ProfileLiked` / `LikeRemoved` | `App.Models.User.{u}` | `.like.changed` | `from_code, is_mutual` (blurred details for Free) | broadcasts |
| `InterestReceived` / `InterestResponded` | via Notification (broadcast channel) | `BroadcastNotificationCreated` | see §M08 | notifications |
| `ProfileViewed` | `App.Models.User.{u}` | `.profile.viewed` | `count_today` (viewer identity only for Gold+) | broadcasts |
| `EntitlementsChanged` | `App.Models.User.{u}` | `.entitlements.changed` | `plan_code, limits` — composer unlocks instantly after payment | now |
| `ForceLogout` | `App.Models.User.{u}` | `.session.revoked` | — (suspension/ban) | now |
| `AdminQueueCountChanged` | `admin.queues` | `.queue.count` | `queue, count` | broadcasts |

Rules: payloads carry **codes, not ULIDs or PII**; anything sensitive (phone, email, photos behind
privacy) is fetched by the Livewire component through a normal authorized request after the event
arrives. Events implement `broadcastWhen()` to suppress delivery to blocked pairs.

### 9.5 Livewire integration pattern

```php
// app/Livewire/Member/Chat/Thread.php  (abridged)
class Thread extends Component
{
    public Conversation $conversation;
    public string $body = '';
    public array $messages = [];         // current window, oldest → newest
    public ?string $cursor = null;       // oldest loaded message id, for "load earlier"

    public function getListeners(): array
    {
        $id = $this->conversation->id;
        return [
            "echo-private:chat.{$id},.message.sent"      => 'onMessage',
            "echo-private:chat.{$id},.message.read"      => 'onRead',
            "echo-private:chat.{$id},.message.deleted"   => 'onDeleted',
            "echo-private:chat.{$id},.conversation.frozen" => '$refresh',
        ];
    }

    public function send(string $clientId): void
    {
        $this->validate(['body' => 'required|string|max:2000']);
        $message = app(SendMessage::class)->handle(auth()->user()->profile, $this->conversation, $this->body, $clientId);
        $this->messages[] = MessageData::from($message)->toArray();   // confirm optimistic bubble
        $this->reset('body');
    }

    public function onMessage(array $payload): void
    {
        $this->messages[] = $payload;
        app(MarkConversationRead::class)->handle(auth()->user()->profile, $this->conversation, $payload['id']);
        $this->dispatch('chat-scroll-bottom');
    }
}
```

Alpine handles the optimistic bubble and typing:

```html
<form x-data="composer()" @submit.prevent="submit($wire)">
  <textarea wire:model="body" @input.throttle.2000ms="whisperTyping()"></textarea>
</form>
<script>
function composer() {
  return {
    submit($wire) {
      const clientId = crypto.randomUUID();
      this.$dispatch('optimistic-message', { clientId, body: $wire.body });   // render grey bubble now
      $wire.send(clientId);                                                     // server confirms or fails
    },
    whisperTyping() {
      Echo.join(`presence-chat.${this.$wire.conversation.id}`).whisper('typing', { at: Date.now() });
    },
  };
}
</script>
```

### 9.6 Presence & last seen
- The member layout joins `presence.online` once per session. `here/joining/leaving` update an
  Alpine store (`$store.online`) that every `<x-online-dot :code>` reads — no Livewire round trip.
- On `leaving`, a debounced job writes `users.last_seen_at` (members may hide it: privacy setting).
- Members with `show_online_status = false` do not join the presence channel at all.

### 9.7 Delivery guarantees & fallbacks
1. **The database is the source of truth; the socket is a notification.** Every message and
   notification is persisted before it is broadcast. On reconnect, Echo fires `connected` → the
   Thread/Inbox/Bell components call `$refresh`/`loadSince($lastId)` to fill any gap.
2. **Polling fallback:** if the socket cannot connect within 10 s (corporate firewall), components
   switch on `wire:poll.10s` for the chat thread and `wire:poll.30s` for the bell (feature flag
   `realtime.polling_fallback`).
3. **Idempotency:** `messages.client_id` unique per conversation — double-clicks and retries never
   duplicate.
4. **Ordering:** ULIDs sort by time; the client inserts by id, so late/out-of-order events render
   in the right place.
5. **Multi-tab / multi-device:** all of a user's tabs receive the same events; read state syncs via
   `.message.read`.

### 9.8 Scaling & operations
- Reverb on its own host(s); Nginx `proxy_pass` with `Upgrade`/`Connection` headers, 60 s
  ping/pong. Raise `ulimit -n` (≥ 10,000) and use the `ev`/`uv` event loop extension for > 1,000
  concurrent connections.
- Broadcast jobs on a dedicated Horizon `broadcasts` queue so a mail backlog never delays chat.
- Rate limits: client events (whispers) are limited by Reverb app config; server-side send limited
  to 30 messages/min per sender and 5 identical messages/10 min (spam).
- Monitoring: Laravel Pulse Reverb cards (connections, messages/sec), alert when connections drop
  > 30 % in 5 min.

---
## 10. Member & Broker Modules (M01–M16)

| Module | Name | Template pages |
|---|---|---|
| M01 | Registration, Login & OTP | index (hero form), register, login, forgot-password |
| M02 | Profile Creation Wizard | profile-creation, education, family, partner, contact-details, profile-photos |
| M03 | Profile View (own & others) | my-profile, single-profile |
| M04 | Search, Filters & Saved Searches | search, all-profiles |
| M05 | Matches, Dashboard & Recommendations | dashboard, my-matches, daily-matches |
| M06 | Likes, Favorites & Interests | interest (+ new likes, favorites pages) |
| M07 | Real-Time Chat | messages |
| M08 | Real-Time Notifications | notifications, header bells |
| M09 | Verification, Trust & Safety | verification |
| M10 | Membership, Payments & Add-ons | package, checkout |
| M11 | Photos & Media Privacy | profile-photos, single-profile gallery |
| M12 | Content & Public Pages | about, branches, success-stories, contact, privacy, terms, faq, 404, index |
| M13 | Broker Portal → **see §11A** | new `/broker/*` |
| M14 | Account Settings & Privacy | new `/settings/*` |
| M15 | Activity: Visitors, Liked-me, Viewed | new `/visitors`, likes tabs |
| M16 | Success Story Submission & Profile Closure | new, linked from delete-profile flow |

---

### M01 — Registration, Login & OTP

**Purpose.** Get a real, reachable person into the system with the least friction, and keep
accounts secure.

**Features**
- Quick-register on the home hero (`Public\QuickRegister`): *Profile for* (Myself / Son /
  Daughter / Brother / Sister / Relative / Friend), name, gender (auto-derived for Son/Daughter/
  Brother/Sister), mobile, email (optional), password, terms consent.
- Mobile OTP verification (mandatory before the wizard). Resend with 30 s countdown (Alpine).
- Optional broker code field + `?ref=BRK1042` 30-day cookie (M13 R-M13-9).
- **Claim flow**: if the mobile matches an unclaimed broker-managed profile, offer "Is this you?"
  (§11A B.6).
- Login by OTP or password; "stay logged in"; login throttling; new-device email alert.
- Forgot password via OTP; password rules: min 8, not in breached list (`Password::uncompromised()`).
- Logout everywhere (invalidates other sessions + `ForceLogout` broadcast).

**Livewire components:** `Public\QuickRegister`, `Member\Auth\Register`, `Member\Auth\VerifyOtp`,
`Member\Auth\Login`, `Member\Auth\ForgotPassword`, `Member\Auth\ClaimProfile`.

**Flow**
```
Register form ─► validate (phone unique, age by gender later in M02) ─► create user (phone_verified_at NULL)
   ─► SendOtp (MSG91) ─► VerifyOtp ─► phone_verified_at = now
   ─► broker code? ResolveBrokerCode (never blocks) ─► create DRAFT profile ─► redirect /onboarding/1
```

**Business rules**
- R-M01-1 One account per mobile number; email unique if given.
- R-M01-2 OTP: 6 digits, hashed at rest, 5-min TTL, 3 wrong attempts invalidates, send limits §8.2.
- R-M01-3 Unknown broker code never blocks signup (R-M13-1).
- R-M01-4 Login response and forgot-password response are identical whether or not the account
  exists (no enumeration).
- R-M01-5 Suspended/banned users cannot log in; message links to support.

**Acceptance**
- [ ] Registration without OTP verification cannot reach the wizard.
- [ ] 4th OTP send in 15 minutes is refused with a retry-after.
- [ ] Registering with a managed profile's mobile shows the claim prompt; "Not me" continues fresh.
- [ ] Login/forgot flows do not reveal whether a number is registered.

---

### M02 — Profile Creation Wizard

**Purpose.** Collect a complete, searchable profile in six short steps, mirroring the template
pages, with autosave so families can finish over several sittings.

**Steps & fields** (template fields + competitor-standard additions marked ➕)

| Step | Template page | Fields |
|---|---|---|
| 1 Basic | profile-creation | first/last name, gender, DOB (18+ F / 21+ M), height, weight, ➕marital status, ➕children, ➕physical status, religion, caste (filtered by religion) / caste-no-bar, ➕sub-caste, ➕mother tongue, star, ➕rasi, ➕dosham (chovva/papa, yes/no/don't know), broker code |
| 2 Education & career | education | highest education, ➕education detail/institute, occupation, ➕employer type (Govt/Private/Business/Self/Not working), ➕company, annual income band, country living in, ➕citizenship/visa (NRI), current & permanent location (district/state/country) |
| 3 Family | family | father's & mother's name and occupation, brothers/sisters (married/unmarried), family status (middle/upper-middle/rich/affluent), ➕family type (joint/nuclear), ➕family values (traditional/moderate/liberal), ➕native place, ➕about family |
| 4 Partner preferences | partner | age range, height range, marital status (multi), physical status, religion, caste (multi, or any), ➕mother tongue (multi), ➕star (multi), education, occupation, income, ➕country/district (multi), ➕diet |
| 5 Contact | contact-details | mobile (pre-filled, verified), alternate mobile, email, ➕contact person & relation, ➕convenient time to call, country/state/city |
| 6 Photos & about | profile-photos | profile photo, up to 9 additional photos, captions, photo visibility (M11), ➕horoscope upload (PDF/JPG), ➕about me (min 50 chars), ➕lifestyle (diet, smoking, drinking, hobbies) |

**Livewire:** one full-page component `Member\Onboarding\Wizard` with a `$step` property and a Form
Object per step (`BasicForm`, `CareerForm`, `FamilyForm`, `PreferenceForm`, `ContactForm`,
`PhotosForm`). Next/Prev navigation without reloads; each step **saves on Next** and every 20 s of
inactivity (`wire:model.blur` + debounced autosave). Progress bar and completeness meter update
live. Dependent selects (religion → caste; country → state → district) are reactive.

**Business rules**
- R-M02-1 Step 1 fields religion, gender, DOB, marital status are editable only until first
  publish; after that, changes go through support (prevents identity drift).
- R-M02-2 Submitting step 6 moves the profile `DRAFT → PENDING_REVIEW`; it appears in A04. Only
  `ACTIVE` profiles are searchable.
- R-M02-3 Completeness = weighted sum (basic 25, career 15, family 15, preferences 15, contact 10,
  photo 15, about 5). Profiles < 60 % are ranked lower in search.
- R-M02-4 Any later edit of text fields (about, names) re-enters moderation for those fields only
  (A04 "edited fields" queue); the profile stays live.
- R-M02-5 A rejected profile shows the moderator's reason on the dashboard with an "Edit & resubmit"
  button.

**Acceptance**
- [ ] Refreshing mid-wizard restores the last saved step and values.
- [ ] Caste options change instantly when religion changes, with no page reload.
- [ ] A female under 18 / male under 21 cannot pass step 1.
- [ ] Completing step 6 creates an A04 queue item and a live count increment on `admin.queues`.

---

### M03 — Profile View (own & others)

**Purpose.** Present a profile richly while enforcing privacy and entitlements on every field.

**Features — other member's profile (`/profile/{code}`)** (from `single-profile.php`)
- Header: photo gallery (Swiper, respects photo privacy → blurred with "Request photo"), name
  (last name initial only until interest accepted), code, age, height, location, education,
  occupation, **Verified** badge, **Premium** badge, online dot / last seen.
- Action bar (all Livewire, no reload): **Like ♥**, **Favorite ☆**, **Send Interest**, **Chat**
  (enabled after acceptance + entitlement), **View Contact** (consumes a contact view; confirms
  first), **Request Photo / Horoscope**, overflow menu: **Ignore, Block, Report, Share link**.
- Tabs: *Personal Information* (Basic, Contact [masked], Professional, Religious, Family,
  Lifestyle) and *Partner Preference* with a **"You match X of Y preferences"** checklist (green
  ticks for each preference the viewer satisfies — KeralaMatrimony-style).
- "About" section, Similar Profiles (6), Prev/Next profile (from the current result set, stored
  in session), success-stories rail.
- Records a `profile_views` row (unless viewer is incognito on Diamond) and fires
  `ProfileViewed`.

**Features — own profile (`/me`)** (from `my-profile.php`)
- Completeness meter with "add X to reach 100 %" suggestions, each linking to the exact wizard
  step; "preview as others see it"; status banner (pending review / rejected reason / hidden);
  quick stats (views this week, likes, interests).

**Business rules**
- R-M03-1 Blocked pairs get 404 on each other's profile (not 403).
- R-M03-2 Contact details visible only if: viewer has contact-view entitlement **and** target's
  `phone_visibility` allows it (PREMIUM_ONLY / ACCEPTED_ONLY) **and** no contact filter excludes
  the viewer. Each reveal is logged in `contact_views` and counted once per pair.
- R-M03-3 Non-`ACTIVE` profiles are visible only to the owner, their managing broker, and admins.
- R-M03-4 Free members see full profiles (market research: let people judge the pool before
  paying) — only contact details and chat are gated.

**Acceptance**
- [ ] Viewing a profile increments the target's "viewed me" live badge within 2 s.
- [ ] Clicking Like/Favorite toggles instantly (optimistic) and persists; double-click is idempotent.
- [ ] A blocked member's profile URL returns 404 for both parties.

---

### M04 — Search, Filters & Saved Searches

**Purpose.** Fast, reactive discovery with the filters Kerala families actually use.

**Search modes**
1. **By profile ID** (`OPM12370`) — direct jump (template `id-search` form).
2. **Quick search** — gender (defaults to opposite), age, religion, caste, district.
3. **Advanced search** — all filters below, in a collapsible left rail (sticky, 3/6/3 layout).

**Filters (all combinable)**

| Group | Filters |
|---|---|
| Basic | age range, height range, marital status, children, physical status, mother tongue |
| Religion & community | religion, caste (multi, religion-dependent), caste-no-bar, sub-caste, star (multi), rasi, dosham |
| Location | country, state, district (Kerala 14 + others), NRI only, citizenship |
| Education & career | education level (min), field, occupation (multi), employer type, annual income (min) |
| Family | family status, family type, family values |
| Lifestyle | diet, smoking, drinking |
| Profile quality | with photo, verified only, premium only, profile created by (self/parent), active within (1 day / 1 week / 1 month), newly joined (7 days) |
| Exclusions (on by default) | hide profiles I've ignored / blocked / already sent interest / already viewed (toggle) |

**Sort:** Relevance (match score), Newest, Last active, Recently verified.

**Livewire behaviour (`Member\Search\Search`)**
- Every filter is a `#[Url]` property → shareable/bookmarkable URLs; browser back restores filters.
- `wire:model.live.debounce.400ms` on inputs; results region shows skeleton loaders
  (`wire:loading`), **no page reload**.
- Result count updates live ("1,248 profiles").
- Pagination: "Load more" / infinite scroll (`x-intersect` → `$wire.loadMore()`), 20 per page,
  cursor-based on `(score, published_at, id)`.
- Result card = `<x-profile-row>` with inline Like / Favorite / Send Interest buttons.
- Mobile: filters in a bottom sheet with "Apply (1,248)" button.
- **Saved searches** (max 10): name + alert frequency; daily/weekly job emails and notifies new
  matches since last alert.

**Search service.** `App\Services\ProfileSearch` builds the query; always applies:
`status = ACTIVE`, opposite gender, not deleted/suspended, not blocked in either direction, not
incognito, **contact filter** (profiles whose contact filter excludes the searcher are shown but
their "Send Interest" is disabled with a tooltip "This member accepts interests only from profiles
matching their preferences"). Premium/highlighted profiles get a boost in Relevance sort.

**Acceptance**
- [ ] Changing any filter updates results in < 800 ms p95 at 100k profiles.
- [ ] Copying the URL into another browser reproduces the same filters and results.
- [ ] Blocked and ignored profiles never appear.
- [ ] Saved search alert contains only profiles published after `last_alerted_at`.

---

### M05 — Matches, Dashboard & Recommendations

**Dashboard (`dashboard.php`, canonical 3/6/3 layout)**
- Left rail: profile card (photo, name, code, plan chip, completeness), membership box with
  **Upgrade** CTA, menu (My Profile, Partner Preferences, My Matches, Likes, Favorites, Interests,
  Messages [live unread badge], Visitors, Verify Profile, Settings).
- Centre: **Daily Recommendations** slider with countdown to the next batch (Swiper, lazy
  `#[Lazy]`), **New matches**, **Liked you** strip (blurred for Free), **Recently viewed you**
  (Gold+), **Mutual matches**, **Premium members** — each with "View All".
- Right rail: ads / promos (`ads.php`), verification nudge, success story.
- Activity counters update live from `App.Models.User.{id}` events.

**My Matches (`my-matches.php`)** tabs: *All matches* (mutual partner-preference fit), *New*,
*Yet to be viewed*, *Viewed*, *Mutual* (I match theirs and they match mine), *Near me* (same
district), *Premium*. Funnel counters (2 × 2).

**Daily matches (F06 in v4, unchanged in logic).** Scheduler at 05:00 IST generates up to 10
matches per active member: candidate filter = partner preferences (hard: gender, age, religion,
marital status; soft: others) → score (preference fit 60 %, reverse fit 25 %, activity 10 %,
completeness 5 %) → diversity (max 3 from one district) → exclude seen/blocked/ignored/interacted
→ persist `daily_matches` → notify ("Your 10 new matches are ready"). Batch expires at 23:59;
countdown on the dashboard.

**Acceptance**
- [ ] Every active member with a complete preference set has a daily batch by 06:00 IST.
- [ ] A match liked, favourited or ignored from the slider disappears/updates without reload.

---

### M06 — Likes, Favorites & Interests

**Purpose.** Three levels of intent, from lightest to strongest, all live.

| Action | Meaning | Visibility to target | Notification | Entitlement | Unlocks |
|---|---|---|---|---|---|
| **Like ♥** | "I like your profile" — casual, one tap | Target sees it in *Liked me* (Free: blurred list + count) | Real-time + digest | Daily limit (§7.3) | If **mutual** → "It's a match!" prompt to send interest; both get a notification |
| **Favorite ☆** | Private bookmark (+ private note) | **Never visible**, never notified | None | Total cap (§7.3) | Appears in `/favorites`, favourites-only filter in search |
| **Interest 💌** | Formal proposal, optional templated message | Target sees it in *Interests → Received* | Real-time + email + SMS (per prefs) | Monthly quota | **Accept → opens a conversation (chat)** and reveals last name; Decline → sender notified politely ("not interested at this time") |
| **Photo / Horoscope request** | Ask to see protected content | Target sees request | Real-time | Free | Target approves → requester gets access |

**Interest state machine** (v4 F02, unchanged): `PENDING → ACCEPTED | DECLINED | WITHDRAWN (by
sender) | EXPIRED (30 days)`. Declined pairs cannot re-send for 90 days. Accepting creates
`conversations` (pair_key) and a SYSTEM message "You are now connected".

**Screens**
- `/likes` — tabs *Liked me*, *I liked*, *Mutual*; "new" dots for unseen likes (`likes.seen_at`).
- `/favorites` — grid with private notes, remove, "send interest to all selected" (respecting
  quota).
- `/interests` — tabs *Received (pending)*, *Accepted*, *Declined*, *Sent*, *Expired*; Accept /
  Decline inline (`profile-row` with `$profile_actions = 'respond'`), bulk decline.

**Livewire**
- `Shared\LikeButton` and `Shared\FavoriteButton` — tiny nested components with
  `#[Locked] public string $profileId`, optimistic Alpine state (`x-data="{liked: @entangle('liked')}"`),
  server `toggle()` calls `Engagement\ToggleLike` / `ToggleFavorite` Actions.
- `Shared\InterestButton` — opens a modal with 5 templated messages (+ custom for paid), shows
  remaining quota, handles quota-exceeded → upgrade modal.
- Lists listen on `echo-private:App.Models.User.{id},.like.changed` and on notifications to prepend
  new items live.

**Business rules**
- R-M06-1 All toggles are idempotent (unique constraints + `firstOrCreate`/`delete`).
- R-M06-2 Like spam guard: > 50 likes in 10 minutes → soft block for 1 hour + flag in A13.
- R-M06-3 Interests respect the target's **contact filter** — refused with a clear message.
- R-M06-4 Blocking removes likes, favorites and pending interests in both directions and freezes
  the conversation.
- R-M06-5 Unliking within 24 h silently removes the like from the target's list (no "unliked"
  notification ever).
- R-M06-6 Favorites are never exposed to the target, admins see them only in aggregate.

**Acceptance**
- [ ] Like → target's bell and *Liked me* list update in < 2 s without refresh.
- [ ] Mutual like shows "It's a match!" to the second liker immediately.
- [ ] Accepting an interest makes the "Chat" button active on both sides live.
- [ ] Quota exhaustion shows the upgrade modal; server refuses even if the UI is bypassed.

---

### M07 — Real-Time Chat

**Purpose.** Let connected members talk instantly, safely, without ever reloading the page.

**Features**
- Two-pane inbox inside the template's 3/6/3 layout: conversation list (left rail), thread
  (centre), profile summary + safety tips (right rail). Below 992 px one pane at a time with a back
  arrow (template requirement).
- Conversation list: avatar, name, online dot, last message preview, time, unread badge, muted icon;
  search by name; filters *All / Unread / Archived*; **re-orders live** on new messages
  (`inbox.{profileId}` channel).
- Thread: day separators, same-sender grouping, **optimistic send** (grey bubble → confirmed),
  **delivery ticks** ✓ sent / ✓✓ delivered / blue ✓✓ read (respecting read-receipt privacy),
  **typing indicator** ("Anjali is typing…", whisper on presence channel, auto-expires 4 s),
  **online / last seen** in header, **load earlier** on scroll-up (cursor pagination, 30 per page,
  scroll position preserved), **unsend** within 60 min ("This message was deleted"), emoji picker,
  **image messages** (paid plans, max 5 MB, moderated), quick replies/icebreakers for the first
  message, mute, archive, block, report (with message selection).
- Global **unread chat badge** in header and mobile tab bar (`Shared\ChatBadge`), live.
- Toast when a message arrives while on another page ("New message from Anjali — Reply"),
  suppressed when the thread is open; optional sound; browser tab title "(2) Messages".
- Works across tabs/devices; reconnect gap-fill (§9.7).

**Livewire:** `Member\Chat\Inbox` (list, listens `inbox.{profileId}`), `Member\Chat\Thread`
(listens `chat.{id}`, joins `presence-chat.{id}`), `Shared\ChatBadge`, `Shared\ToastStack`.
Composer uses Alpine for optimistic UI and typing whispers (§9.5).

**Send flow**
```
Alpine: render optimistic bubble (clientId) ─► $wire.send(clientId)
Server Chat\SendMessage (DB transaction):
   ├─ authorize: participant, conversation OPEN, no block, sender entitlement can_message
   │             (Free → one reply allowed per conversation, R-M07-3)
   ├─ rate limit (30/min) + duplicate-spam check
   ├─ ContentSafety::scan(body): phone/email/URL/UPI patterns in first 10 messages → mask + flag;
   │             profanity (EN + Malayalam + Manglish list) → flag; never silently drop
   ├─ INSERT messages (unique conversation_id+client_id → idempotent)
   ├─ UPDATE conversation last_message_*, recipient unread_count + 1
   └─ after commit: broadcast MessageSent (toOthers) + ConversationUpdated(recipient)
                    + NewMessage notification (database; email/SMS only if recipient offline
                      > 10 min and not muted — batched digest)
Recipient Thread (open): onMessage → append → MarkConversationRead → broadcast MessagesRead
Recipient elsewhere: Inbox re-orders, ChatBadge +1, toast
```

**Business rules**
- R-M07-1 Chat exists only between members with an **accepted interest** (or mutual like +
  accepted interest); no cold messaging.
- R-M07-2 Sending requires a paid plan; Free members can read all messages.
- R-M07-3 Free members may send **one reply per conversation** (market research: reduce the
  "pay to say hello" frustration) — configurable in A15.
- R-M07-4 Max 2,000 characters; images only on paid plans; no files/links in first 10 messages.
- R-M07-5 Blocking or admin freeze makes the thread read-only for both, instantly (broadcast
  `ConversationFrozen`).
- R-M07-6 Messages retained 2 years after the last activity, or deleted 30 days after both
  accounts are deleted; admins read a conversation only via break-glass (A13).
- R-M07-7 Read receipts are symmetric: if you turn them off you don't see others' either.

**Acceptance**
- [ ] Message appears on the recipient's open thread in < 1 s p95 (same region), without reload.
- [ ] Sending the same `clientId` twice creates one message.
- [ ] Typing indicator shows within 500 ms and clears 4 s after the last keystroke.
- [ ] After a network drop of 60 s, reconnect back-fills all missed messages in order.
- [ ] Free member composer shows "Upgrade to chat" after the one free reply; server returns 403 if
  bypassed.
- [ ] A blocked pair's thread becomes read-only on both screens live.

---

### M08 — Real-Time Notifications

**Purpose.** Tell members what happened, instantly in-app and on their preferred channels,
without spamming them.

**Notification types**

| Event (`notification_preferences.event`) | In-app (live) | Email | SMS | Grouping |
|---|---|---|---|---|
| `interest_received` | ✅ | ✅ | ✅ (default on) | per sender |
| `interest_accepted` | ✅ | ✅ | ✅ | — |
| `interest_declined` | ✅ | ❌ | ❌ | — |
| `new_message` | badge + toast | offline digest (10 min) | ❌ | per conversation |
| `profile_liked` / `mutual_like` | ✅ | daily digest | ❌ | "5 people liked you today" |
| `profile_viewed` | ✅ (Gold+ identity) | weekly digest | ❌ | daily roll-up |
| `photo_request` / `horoscope_request` (+ approved) | ✅ | ✅ | ❌ | — |
| `daily_matches_ready` | ✅ | ✅ | ❌ | — |
| `saved_search_alert` | ✅ | ✅ | ❌ | per search |
| `profile_approved` / `profile_rejected` | ✅ | ✅ | ✅ | — |
| `verification_approved` / `rejected` | ✅ | ✅ | ✅ | — |
| `payment_success` / `plan_expiring` (7d, 1d) / `plan_expired` | ✅ | ✅ | ✅ | — |
| `admin_announcement` / campaign | ✅ | optional | optional | — |
| `safety_warning` (account warned) | ✅ (cannot disable) | ✅ | ✅ | — |

**Implementation**
- Each is a Laravel `Notification` class with `via()` = `['database', 'broadcast']` + `mail` /
  `SmsChannel` per `notification_preferences` and quiet hours (22:00–07:00 IST: SMS deferred).
  `ShouldQueue` on the `notifications` queue.
- `toArray()` stores `{type, actor_code, actor_name, actor_photo_thumb, title, body, url, icon}` —
  rendered by one Blade partial per type. Icons follow the template (`fa-heart`, `fa-bolt`,
  `fa-envelope`, `fa-eye`, `fa-cog`).
- `toBroadcast()` sends the same minimal payload on `App.Models.User.{id}`.

**UI**
- `Shared\NotificationBell` (header, both desktop and mobile): live unread count (replaces
  template's hardcoded "3"), dropdown with the latest 10 **lazy-loaded when opened**, "Mark all as
  read", link to `/notifications`. Listens:
  `echo-private:App.Models.User.{id},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated`.
- `Shared\ToastStack`: slide-in toast for each live notification (auto-dismiss 6 s, click → url).
- `/notifications` page (`notifications.php`): filter by type (Interests, Likes, Messages, Views,
  Matches, System) and *Unread*, **infinite scroll** (cursor, 20 per load), mark one/all read,
  delete; grouped by Today / Yesterday / Earlier.
- Browser tab title count; optional sound; web push in v1.5.

**Business rules**
- R-M08-1 Never notify a blocked actor's actions; never notify favourites; never notify "unliked".
- R-M08-2 Aggregate noisy events (likes, views) — max 1 in-app notification per actor per day per
  type.
- R-M08-3 Email/SMS always carry an unsubscribe/preferences link; transactional and safety
  notifications cannot be disabled.
- R-M08-4 Notifications older than 180 days are pruned (scheduled).

**Acceptance**
- [ ] Bell count increments live on every notifiable event, and decrements across all open tabs
  when read in one.
- [ ] Opening the dropdown loads items lazily (no query on page load beyond the count).
- [ ] With email off for `profile_liked`, no email is sent but the in-app notification still shows.

---

### M09 — Verification, Trust & Safety

**Features**
- **ID verification** (`verification.php`): document type (Aadhaar — masked, last 4 digits only
  stored; Passport; Driving licence; Voter ID), number, front/back upload (JPG/PNG/PDF ≤ 5 MB),
  consent checkbox. Stored on the **private** disk, encrypted, reviewed in A05, originals deleted 90
  days after decision (only the decision + masked number kept). Approved → **Verified badge** and
  "verified only" search visibility.
- **Mobile verified** badge (from OTP) and **email verified**.
- **Block** (silent, symmetric), **Ignore** (one-way hide), **Report** with reasons (fake profile,
  already married, inappropriate photos, abusive messages, asking for money, spam, other) +
  optional evidence (message selection auto-attached). Reports go to A10/A13.
- **Safety centre** page: tips (never send money, meet in public, involve family), how to report,
  emergency contacts.
- Automated trust signals (feed A10): many reports, many declined interests in short time,
  duplicate photos across profiles (perceptual hash), mismatched age vs photo flags, disposable
  emails.

**Acceptance**
- [ ] ID documents are never served from a public URL; access requires `verification.document.view`
  and is audited.
- [ ] Reporting from chat attaches selected messages and freezes nothing until an admin acts
  (unless 3+ independent reports → auto-freeze pending review).

---

### M10 — Membership, Payments & Add-ons

**Features**
- Plans page (`package.php`) from `plans` table: Silver / **Gold (Most Popular)** / Diamond,
  monthly / 3-month / 6-month / 12-month durations with discount, feature comparison table (from
  `plan_features`), FAQ.
- Checkout (`checkout.php`): plan summary, coupon code (Livewire-validated live), GST 18 % line,
  total **computed server-side** from the plan key, **Razorpay Checkout** (UPI, cards, netbanking,
  wallets). No card fields on our pages.
- Webhook `payment.captured` → `ActivateSubscription` (idempotent on `razorpay_payment_id`) →
  entitlements granted → `EntitlementsChanged` broadcast (chat composer unlocks live) →
  `payment_success` notification → invoice PDF (GST invoice, sequential number) → broker commission
  event (M13) if applicable.
- Billing history page: orders, invoices (download), current plan, usage meters (interests left,
  contact views left), auto-renew toggle (Razorpay subscriptions, v1.5).
- Add-ons (v1.5): Profile Boost (7 days top placement), extra contact views pack.
- Expiry: reminders at 7 days and 1 day; on expiry revert to Free limits (data kept).
- Refunds initiated only by admin (A06); full refund voids broker commission (R-M13-5).

**Acceptance**
- [ ] Tampering with the amount client-side has no effect — amount comes from the plan key.
- [ ] Replayed webhook does not double-activate.
- [ ] After payment, the chat composer unlocks without reload.

---

### M11 — Photos & Media Privacy

- Upload via Livewire `WithFileUploads` (temporary S3 upload, progress bar), crop/rotate (Cropper.js
  via Alpine), max 10 photos, JPG/PNG/WebP/HEIC ≤ 10 MB, min 400×400.
- Conversions (medialibrary, queued `media`): thumb 200, card 600, full 1200, **blurred** 600;
  EXIF stripped; perceptual hash stored for duplicate detection.
- Every photo `PENDING` → moderated in A04 → `APPROVED` / `REJECTED` (reason). Only approved photos
  are public; the owner sees pending ones with a label.
- Set primary photo, reorder (drag, Alpine sortable), delete, caption.
- **Visibility** (`privacy_settings.photo_visibility`): All members / Premium only / On request /
  Accepted connections only. Non-permitted viewers get the blurred conversion + "Request photo".
- Light watermark with the profile code on full-size images; right-click/save deterrents
  (cosmetic).
- Horoscope file: PDF/JPG, private disk, served via signed temporary URL to permitted viewers.

---

### M12 — Content & Public Pages

From the template, all content editable in A08:
- **Home** (`index.php`): hero carousel (banners), quick register, about teaser, 3 steps, plans,
  testimonials, featured members (premium + verified, photo-visible-to-all only), counters
  (members, marriages, branches — cached daily).
- About, **Branches** (list + map link + phone + hours, from `branches`), **Success stories**
  (grid, individual anchors, member-submitted via M16 → approved in A08), Contact form (stored,
  emailed to support, reCAPTCHA/Turnstile), Privacy, Terms, FAQ (in `package#faq`), 404 with
  real HTTP 404.
- SEO: server-rendered meta, OpenGraph, canonical, sitemap.xml (public pages + community landing
  pages like `/christian-matrimony-kerala`, `/nair-matrimony`), robots controlled by
  `config('app.indexable')`; member pages `noindex`.

---

### M13 — Broker Portal (member side of the broker module)

**Moved to the dedicated broker chapter, §11A.** It covers the referral program (B.5), managed
profiles, client inbox and claim flow (B.6), export center (B.7), staff accounts (B.8), bulk upload
(B.9), commission (B.10) and all broker portal screens (B.11).

---

### M14 — Account Settings & Privacy

`/settings` sections (Livewire tabs, no reload):
- **Account**: change mobile (OTP both old and new), email (verify), password; active sessions list
  with "log out other devices".
- **Privacy**: photo visibility, phone visibility, horoscope visibility, show online status, show
  last seen, read receipts, **incognito** (Diamond: browse without appearing in "who viewed me"; all
  plans: hide from search temporarily), **contact filter** ("only members matching my partner
  preferences can send me interests/messages").
- **Notifications**: matrix of events × channels (in-app / email / SMS / push), quiet hours.
- **Blocked & ignored** lists with unblock/unignore.
- **Hide / delete profile**: hide (reversible any time) or delete with reason (*married via Oppam*
  → M16 success-story prompt; *married elsewhere*; *not interested*; *other*); 30-day recovery
  window, then anonymisation job (DPDP right to erasure); download my data (JSON + photos zip,
  queued, emailed link, 24 h expiry).

---

### M15 — Activity: Visitors, Likes & Views

- `/visitors` — **Who viewed my profile** (Gold/Diamond: full list with time; others: count +
  blurred teaser + upgrade CTA), **Profiles I viewed** (all plans, 90 days).
- Likes tabs (M06) — *Liked me* (Free: blurred + count), *I liked*, *Mutual*.
- "Who favourited me" is **not** offered (favorites are private by design, R-M06-6).
- Live: new visitor → `.profile.viewed` → counter bump + (Gold+) prepend row.

---

### M16 — Success Stories & Profile Closure

- When deleting with reason "Found my match on Oppam", prompt for partner's profile code (partner
  gets a confirmation request), wedding date, photo, story → `success_stories` (PENDING) → A08
  approval → shown on `/success-stories` and home testimonials (with both parties' consent).
- Both profiles are auto-hidden once confirmed.

---
## 11. Admin Panel (A01–A15) — `admin.oppam.in`

### 11.0 Admin panel foundations

- **Stack:** Blade + Livewire 3 + Alpine, `admin` guard, `routes/admin.php` under
  `Route::domain(config('app.admin_domain'))`, middleware `web, auth:admin, 2fa.confirmed,
  ip.allowlist, admin.active`.
- **Layout (`layouts/admin.blade.php`):** collapsible left sidebar (sections below, each item hidden
  by `@can`), top bar with global search (member code / phone / email / order no.), live queue
  badges, admin notification bell, profile menu; content area; toast stack. Uses the same design
  tokens as the member site (`--color-primary #e02349`, `--color-secondary-dark`) with a neutral
  admin skin; light/dark mode.
- **Shared admin UI kit:** `<x-admin.table>` (sortable columns, filters bound to `#[Url]`
  properties, `WithPagination`, column chooser, bulk-select), `<x-admin.stat-card>`,
  `<x-admin.confirm-modal>` (typed-reason confirmation for destructive actions),
  `<x-admin.drawer>` (side panel detail), `<x-admin.timeline>`, `<x-admin.diff>`, charts via
  Chart.js (Alpine wrapper).
- **Real-time in the admin panel:** `admin.queues` channel updates sidebar badges and queue pages
  live (new profile to moderate, new verification request, new report, flagged chat); `admin.dashboard`
  ticks today's signups/payments; claim-locks broadcast so two moderators see "being reviewed by
  Divya".
- **Every sensitive action** is authorized inside the component action with
  `$this->authorize('permission.key')` (a hidden button is presentation only) and written to
  `audit_logs` by the Action layer.

**Admin sidebar**

| Section | Items (module) |
|---|---|
| Overview | Dashboard (A02) |
| Members | All members, Member detail, Deleted/Recovery (A03) |
| Moderation | Profile queue, Photo queue, Edited-fields queue, Escalations (A04) |
| Verification | ID verification queue (A05) |
| Safety | Abuse cases, Flagged chats, Blocks investigation, Banned identities (A10, A13) |
| Engagement | Interests / likes analytics, Conversations overview (A13) |
| Billing | Plans, Orders, Stuck orders, Subscriptions, Refunds, Coupons, Invoices / GST (A06) |
| Brokers | Directory, KYC queue, Unverified codes, Managed profiles, Payouts, Export audit (A07) |
| Content | Home blocks, Banners, Success stories, Testimonials, Branches, FAQ, Legal pages, Ads, Contact inbox (A08) |
| Communication | Notifications & campaigns, Announcements, Templates (email/SMS) (A14) |
| Reports | Growth, Engagement, Revenue, Trust & ops, Marketplace health (A09) |
| Support | Tickets, Canned responses (A10) |
| Master data | Religions, Castes, Stars, Rasi, Districts, Education, Occupations, Income, Languages, Options, Reasons (A11) |
| System | Audit log, System health, Horizon, Pulse, Reverb status (A12) |
| Settings | Site settings, Feature flags, Entitlement rules, Integrations, Admin users & roles (A15, A01) |

---

### A01 — Admin Authentication, Staff & Roles
(v4 A01 in full.) Email + password on the `admin` guard; **mandatory TOTP 2FA** with 10 hashed
recovery codes; 3 failed logins → 30-min lock; 5 failed 2FA → lock + alert super admins; idle
timeout 30 min, absolute 12 h; session list & revoke; staff invitation (72 h single-use link, must
set password + 2FA); roles & permissions editor (spatie) with guardrails (cannot grant what you
don't hold; last super admin protected); **time-boxed impersonation** (30 min, reason required,
banner in member UI, blocked actions: password/email/payment, start+end audit rows, member emailed).
Components: `Admin\Auth\Login`, `TwoFactorChallenge`, `Admin\Staff\Index`, `Admin\Staff\Invite`,
`Admin\Roles\Editor`, `Admin\Sessions`.

### A02 — Admin Dashboard & KPIs
(v4 A02 + live.) Alert strip (SLA breaches, webhook failures, queue depth, error rate, Reverb
connection drop); KPI row (total members, new today, active 7d, paid members, revenue today/MTD,
**online now** from Reverb presence count, **messages today**, **likes today**), each with a 30-day
delta and tooltip definition; charts: signups & activations, revenue by plan, interests vs
acceptance, gender ratio; **work-queue cards** (profiles to moderate, photos, verifications,
reports, flagged chats, broker KYC, stuck orders, open tickets) with count + oldest-item age,
**updating live** via `admin.queues`; recent activity feed. KPIs from nightly/hourly snapshot tables
(`metric_snapshots`), never aggregated live; "as of" timestamp shown. Component: `Admin\Dashboard`.

### A03 — Member Management
(v4 A03 in full.) Unified search (code, name, email, phone, order no.); facet filters (account
status, profile status, verified, plan, completeness, gender, religion, district, registered
range, last active, broker yes/no, owner type SELF/BROKER_MANAGED, open reports); bulk actions with
typed reason (suspend, activate, send notification, export) — **no bulk delete**; audited CSV
export (never passwords, document keys, message content).
**Member detail tabs:** Overview (quick actions: reset password, resend OTP, suspend, reactivate,
hide, force re-verification, impersonate, grant complimentary plan, delete with typed code),
Profile (edit with diff + reason), Photos, **Engagement** (likes sent/received counts, interests
by status, favourites count — not identities of favourites), **Conversations** (list with message
counts & flags; content not visible — break-glass only via A13), Subscriptions, Orders (refund),
Activity (logins, devices, IPs), Verification, Reports, Notes (append-only), Timeline (A12).
Suspension revokes sessions (live `ForceLogout`), removes from search/matches, freezes
conversations (live), pauses subscription. Two-stage deletion (30-day restore → anonymise; financial
records retained). Components: `Admin\Members\Index`, `Admin\Members\Show` (tabbed, lazy tabs).

### A04 — Profile & Photo Moderation
(v4 A04 in full.) Separate queues for **new profiles**, **photos**, **edited fields** and
**escalations**; oldest-first with paid-priority lane; 15-min soft claim lock (broadcast "being
reviewed by X"); automated pre-flags (contact info in text, duplicate photo pHash, duplicate
identity, income/occupation mismatch, profanity EN+ML, underage → auto-reject); decisions:
approve / reject (category → member template) / request changes (deep-link to wizard step) /
escalate; **keyboard-driven photo grid** (A/D, arrows, Space, Enter batch); blur-by-default for
moderator wellbeing; provenance flag **"Added by broker BRK1042"** for managed profiles (R-M13-14),
plus **"bulk batch #B-0042 · entered by <staff name>"** for imported ones, with a filter to review a
whole batch together;
approve → `published_at`, match generation, member (or broker) notified live. Moderator quality
metrics for super admin. Components: `Admin\Moderation\ProfileQueue`, `ProfileReview`,
`PhotoQueueGrid`, `EditedFieldsQueue`, `Escalations`.

### A05 — Verification Queue
(v4 A05 in full.) Queue with 48 h/72 h aging colours, paid-first ordering, duplicate-document and
name-mismatch flags; claim lock; `verification.document.view` separate from approve/reject;
**10-minute signed URLs**, in-browser viewer only (no download, `no-store`, watermark admin email +
time, "access is logged"); every view audited; decisions approve / reject (reasons → member
guidance) / escalate; Malayali naming-variant guidance; fraud signals (same doc hash on two
profiles, banned-name match, rapid resubmission); daily purge job verified. Approve → Verified
badge live on the member's profile + notification. Components: `Admin\Verification\Queue`,
`Admin\Verification\Review`.

### A06 — Plans, Subscriptions, Payments & Coupons
(v4 A06 in full.) Plan editor with **live pricing-card preview**, display features vs enforced
entitlements (**including v5 likes/day, favourites cap, free-reply toggle**) with consistency
warning; price changes → new orders only, entitlement changes → immediate (broadcast
`EntitlementsChanged` to affected online members); plan deactivate (never delete if referenced);
orders list & detail with full lifecycle timeline and raw webhook payloads; **stuck orders** view
(re-poll Razorpay / mark failed / force-activate super-admin only); refunds by category, one order at
a time, with automatic side-effects (subscription cancelled, credit note, broker commission voided,
member notified); subscription views (active, expiring 7d, expired 30d win-back, cancelled,
complimentary); complimentary grants (reason, ≤ 12 months, audited); **coupons** (percent/fixed, caps,
per-member limit, validity, plans, first-time-only, min order); gapless GST invoice numbering +
monthly GST CSV; daily reconciliation job with "investigate" rows; add-on products (Boost, contact
pack — v1.5). Components under `Admin\Billing\*`.

### A07 — Broker / Agent Management (admin side of the broker module)

**Moved to the dedicated broker chapter, §11A — B.12** (directory, KYC queue, broker detail tabs,
unverified codes, payout runs, export audit, bulk-import monitor, consent disputes, fraud signals)
and B.16 (rules R-A07-1 … R-A07-11).

### A08 — Content Management
(v4 A08 in full.) Success stories (consent-gated publish, withdrawal within 24 h, member-submitted
stories from M16 arrive here as PENDING), branches, FAQ, legal pages (versioning, effective date,
diff, "not legally reviewed" banner, forced re-acceptance on material change), testimonials,
**homepage blocks and hero banners** (from `index.php`: hero slides, 3 steps, counters labels, CTA),
ad slots (audience FREE_ONLY / PAID / ALL, scheduling, weighted rotation — the template's `ads.php`
rail), contact-submission inbox (assign, reply, convert to ticket, spam tab), community landing
pages (SEO). Editorial workflow DRAFT → IN_REVIEW → SCHEDULED → PUBLISHED → ARCHIVED with
`content.edit` / `content.publish` separated; signed preview URL renders the real public Blade page
against draft data. Rich text via a shared Alpine WYSIWYG (sanitised server-side).

### A09 — Reports & Analytics
(v4 A09 in full, plus engagement for v5.) Growth (signups by source/district/gender/broker, 6-step
activation funnel, cohorts), **Engagement** (DAU/WAU/MAU; **likes sent/received, mutual-like
rate, like→interest conversion**; interests sent/accepted/declined by gender & tier;
**conversations started, messages per conversation, median first-reply time, % conversations with
reply**; search usage: top filters, zero-result rate; saved-search alerts CTR; notification
open/click rates by channel), Revenue (by plan/month/district, free→paid funnel, churn, ARPU, LTV,
refunds, broker-attributed revenue), Trust & ops (verification/moderation SLAs, reports, tickets),
Marketplace health (gender ratio by district & age, photo ratio, zero-interest profiles work queue).
Date presets + compare-to-previous; snapshot-backed; CSV/Excel export with formula neutralisation,
audited, large exports queued & emailed; weekly KPI email digest.

### A10 — Support Desk & Abuse Handling
(v4 A10 in full.) Abuse reports auto-triaged P0/P1/P2 with SLAs; aggregated into one case per
profile; auto-escalation on 3+ distinct reporters in 7 days; case review with profile, photos,
history, **conversation evidence behind an audited "reveal evidence" click** (only messages around
the reported one); outcomes: no action, warning (live `safety_warning` notification), content
removal, hide, suspend, **ban** (email/phone/device/pHash blocklist), legal hold (super admin) with
evidence-pack export; reporter gets "reviewed and closed" only. Support tickets from contact form,
in-app help (auto-attaches plan & last 20 actions) and email-in; categories with SLAs; threaded
view with internal notes; canned responses with variables; member context sidebar; block
investigation (read-only). **Live:** new P0 report pings on-call admins via `admin.queues` + email/SMS.

### A11 — Master Data Management
(v4 A11 in full.) All lists (religions, castes→religion, sub-castes, stars, rasi, doshams,
countries→states→districts, education, occupations, income bands, mother tongues, marital/physical
status, diet/habits/body type/complexion, family types/status/values, rejection reasons, report
reasons, **icebreaker/interest message templates**, **chat profanity wordlists EN/ML/Manglish**);
common row shape (immutable `code`, editable `label`, `label_ml` for v2, `parent_id`, `sort_order`,
`is_active`, `usage_count`); deactivate-not-delete when used; drag-and-drop ordering; CSV
export/import with preview diff; cache-tag flush on every write so wizard/search dropdowns update
within seconds.

### A12 — Audit Logs & System Health
(v4 A12 in full.) Every admin write logged (before/after diff, redacted), narrow read-logging
(document views, evidence reveals, **break-glass conversation reads**), append-only at model and DB
grant level, 7-year retention; viewer with filters and per-entity timeline; system health panel:
Horizon queues (failed/retry), scheduler runs, webhook status & replay, outbound SMS/email delivery
and bounces, latency/error rate, storage, DB stats, **Reverb status** (current connections,
messages/sec, per-channel-type counts, last restart) and **broadcast queue lag**; links to
`/horizon` and `/pulse`. Alerts: failed jobs > 10, missed schedules, webhook signature failures,
bounce > 5 %, broadcast lag > 5 s, Reverb connections drop > 30 %.

### A13 — Chat & Engagement Safety (new in v5)

**Purpose.** Keep real-time chat safe without staff routinely reading private conversations.

**Features**
- **Flagged messages queue**: messages auto-flagged by `ContentSafety` (contact-info sharing in
  first 10 messages, profanity, money/UPI/bank requests, external links, spam repetition) plus
  user-reported messages. Shows only the flagged message with ±3 messages of context, revealed by
  an audited click. Decisions: dismiss, delete message (tombstone "removed by Oppam"), warn sender,
  **freeze conversation** (live `ConversationFrozen`), suspend sender (A03 flow), escalate to A10
  case.
- **Conversation overview** (metadata only): counts per day, top senders by volume, conversations
  with one-sided messaging (≥ 20 unanswered messages → harassment signal), new accounts messaging
  many people.
- **Break-glass read** (`chat.read_conversation`): super admin / compliance only, mandatory reason +
  linked case or legal request, time-limited (30 min), watermarked viewer, audited, member-visible
  in data-access log on request (DPDP).
- **Engagement abuse monitors**: like-spam (R-M06-2), interest-spam (many interests, near-zero
  acceptance), mass favourites via automation, repeated identical messages; each creates a signal
  row with one-click actions (rate-limit, warn, suspend).
- **Wordlist & rules editor** (links to A11 lists): patterns for phone/email/UPI, per-rule action
  (mask / flag / block), dry-run tester against sample text.
- **Live**: new flagged message increments the queue badge via `admin.queues`.

**Components:** `Admin\Chat\FlaggedQueue`, `Admin\Chat\FlagReview`, `Admin\Chat\Overview`,
`Admin\Chat\BreakGlass`, `Admin\Chat\Signals`, `Admin\Chat\Rules`.
**Permissions:** `chat.view_flagged`, `chat.moderate`, `chat.freeze`, `chat.read_conversation`,
`chat.rules.edit`.

**Acceptance**
- [ ] A moderator can act on a flagged message without ever seeing unrelated messages.
- [ ] Freezing a conversation makes both members' composers read-only within 2 s.
- [ ] Every break-glass read and evidence reveal writes an audit row with reason.

### A14 — Notifications, Campaigns & Templates (new in v5)

**Features**
- **Template manager**: email (Blade/Markdown with preview), SMS (DLT template id + variables),
  in-app notification copy per event type; versioned; test-send to self.
- **Announcements**: site-wide or segment banner / in-app notification (e.g. "Onam offer: 20 % off
  Gold"), scheduled start/end, dismissible; delivered live via broadcast to online users.
- **Campaigns**: segment builder (plan, gender, religion, district, completeness, last active,
  registered range, has photo, verified, broker-managed yes/no), channel (in-app / email / SMS /
  push v1.5), schedule, throttle (per minute), **respect preferences & quiet hours**, test send,
  approval by a second admin for > 10,000 recipients, cancel in flight; per-campaign stats (sent,
  delivered, opened, clicked, unsubscribed, bounced).
- **Automated journeys** (configurable, not code): incomplete wizard reminders (1 d, 3 d, 7 d),
  pending-interest reminder (3 d), plan-expiry reminders, win-back (expired 30 d), inactive 14 d
  "new matches for you".
- **Delivery log**: every outbound email/SMS (`outbound_messages`) with status and provider id;
  resend for transactional failures.

**Components:** `Admin\Comms\Templates`, `Admin\Comms\Announcements`, `Admin\Comms\Campaigns`,
`Admin\Comms\CampaignBuilder`, `Admin\Comms\Journeys`, `Admin\Comms\DeliveryLog`.
**Permissions:** `campaigns.view`, `campaigns.send`, `campaigns.approve`, `templates.edit`,
`notifications.broadcast`.

### A15 — Settings & Configuration (new in v5)

- **Site settings**: site name, logo, contact email/phone, social links, SEO defaults,
  `indexable` switch (replaces template's `SITE_LIVE`), maintenance mode message.
- **Business rules (editable, validated, audited)**: minimum ages, interest expiry days (30),
  re-interest cooldown (90), unsend window (60 min), Free reply allowance (1), daily match count
  (10), photo limits, like spam thresholds, broker staff seat default (5), bulk-import row/day caps and auto-pause rejection threshold (40 %), OTP limits, profile auto-hide after inactivity (180 d).
- **Entitlement matrix** (links to A06 plans) with preview of the member-facing comparison table.
- **Feature flags**: `realtime.enabled`, `realtime.polling_fallback`, `chat.images`,
  `likes.enabled`, `broker.self_apply`, `broker.staff`, `broker.bulk_import`, `payments.razorpay_live`, `push.enabled`, `boost.enabled`.
- **Integrations**: Razorpay keys (masked, test/live), MSG91, mail provider, S3, **broadcast
  driver status** (Reverb/Pusher, connection test button), reCAPTCHA/Turnstile — secrets are shown
  masked and stored in `.env`/secret manager; the UI only shows status and allows test calls.
- **Admin users & roles** (A01 screens linked here).

---
## 11A. Broker / Bureau Module — Complete Specification

> **This chapter is the single source of truth for everything broker-related** — the broker portal
> (member side, formerly M13), broker management in the admin panel (formerly A07), the broker data
> model, real-time events, routes, rules, flows, tests and acceptance criteria. Other sections of
> this PRD only point here. Module codes **M13** (broker portal) and **A07** (broker admin) are kept
> so references from v4 still resolve.

### B.1 Purpose & overview

Kerala matchmaking is heavily intermediated. Local marriage brokers and small bureaus (often with a
few employees) do two jobs today: they **introduce** families to matrimony platforms, and they
**run the whole profile** for clients who won't use a website themselves — interviewing the family,
filling every field, taking photos, then printing or WhatsApp-ing a biodata to other families.
Real bureau software (MatchFinder DX, GoClixy franchise tools) treats this as core. Oppam brings
the bureau on-platform, while keeping one hard boundary: **a broker never sees a self-registered
member's private data.**

**The five broker capabilities**

| # | Capability | One-line summary | Section |
|---|---|---|---|
| 1 | **Referral program** | Broker shares a code/link/QR; members who join with it and later pay earn the broker commission | B.5 |
| 2 | **Managed profiles** | Broker creates and runs profiles for offline clients; client can later claim their own profile | B.6 |
| 3 | **Export center** | Bureau-branded biodata PDF and photo zip — only for the broker's own managed profiles | B.7 |
| 4 | **Staff accounts** | Owner + Manager / Data Entry / Telecaller logins with role permissions (new in v5) | B.8 |
| 5 | **Bulk upload** | Import many client profiles from Excel/CSV + photo zip (new in v5) | B.9 |

Plus: **commission & payouts** (B.10), the **broker portal** screens (B.11) and the **admin side**
(B.12).

**Scope history.** v3/v4 designed capabilities 1–3 and deferred 4–5 (v4 rule R-M13-17). **v5 ships
all five in v1** (product decision, 27 Sep 2026).

### B.2 Actors & roles

| Actor | Who | Access |
|---|---|---|
| **Bureau Owner** | The broker who applied and passed KYC; `broker_staff.role = OWNER` | Everything in the bureau, including earnings, payouts, bank/KYC, staff management |
| **Manager** | Senior staff | All bureau profiles, imports, exports (Owner can disable), assignment, client inbox; no money/staff |
| **Data Entry** | Staff who type profiles | Create/edit/import profiles, submit for review, notes; no exports, no client inbox, no money |
| **Telecaller** | Staff who call families | Assigned profiles only, client inbox (accept/decline), notes & reminders; no editing, no exports |
| **Referred member** | A person who self-registered with the broker's code | Normal member; the broker sees only "joined / converted", never their data |
| **Managed client (unclaimed)** | Person whose profile the bureau created | No login yet; bureau acts for them |
| **Managed client (claimed)** | Same person after claiming | Normal self-owned member; bureau loses all access |
| **Admin staff** | Oppam employees with `brokers.*` permissions | Everything in B.12 |

**Bureau permission matrix** (fixed presets in v1; the Owner can switch individual permissions
**off** for a staff member, never on beyond the role's ceiling)

| Permission key | Owner | Manager | Data Entry | Telecaller |
|---|---|---|---|---|
| `bureau.dashboard.view` | ✅ | ✅ | ✅ (own work) | ✅ (own work) |
| `profiles.view` | ✅ all | ✅ all | ✅ per scope | ✅ assigned only |
| `profiles.create` / `profiles.edit` | ✅ | ✅ | ✅ | ❌ (notes only) |
| `profiles.import` | ✅ | ✅ | ✅ | ❌ |
| `profiles.submit_for_review` | ✅ | ✅ | ✅ | ❌ |
| `profiles.activate` / `deactivate` / `delete` | ✅ | ✅ | ❌ | ❌ |
| `profiles.assign` | ✅ | ✅ | ❌ | ❌ |
| `client_inbox.respond` | ✅ | ✅ | ❌ | ✅ |
| `client_notes.write` | ✅ | ✅ | ✅ | ✅ |
| `profiles.export` | ✅ | ✅ (Owner may disable) | ❌ | ❌ |
| `referrals.view` | ✅ | ✅ | ❌ | ❌ |
| `earnings.view`, payouts, bank & KYC | ✅ | ❌ | ❌ | ❌ |
| `staff.manage` | ✅ | ❌ | ❌ | ❌ |
| `materials.view` | ✅ | ✅ | ✅ | ✅ |

**Authentication.** Owners and staff are `users` with `role = BROKER` plus a `broker_staff` row.
They log in at the normal `/login` (mobile + OTP or password) and land on `/broker`. Route group
middleware: `auth`, `role:BROKER`, `broker.active`, `broker.staff.active`; every component action
calls `$this->authorize('bureau', '<permission>')`, resolved by `BureauGate` = role ceiling minus
the Owner's switch-offs, re-checked on every request. Owner and Manager must pass an OTP challenge
on each new device. A broker/staff login never owns a matrimony profile (a broker who wants to
marry registers a separate member account with a different mobile).

### B.3 Broker lifecycle

```
APPLIED ──(admin reviews application)──► KYC_PENDING ──(PAN verified)──► ACTIVE
   │                                          │                            │
   └─ rejected ──► REJECTED                    └─ rejected ──► REJECTED     ├─ admin suspends ──► SUSPENDED ──► ACTIVE
                                                                           ├─ imports auto-paused (flag only, still ACTIVE)
                                                                           └─ admin deactivates ──► DEACTIVATED (history kept)
```

- **Entry points:** self-apply at `/broker/apply` (behind feature flag `broker.self_apply`) or
  created by an admin (A07). Either way, the code `BRK` + sequence is **system-generated**.
- **Before KYC:** the broker can log in, see an empty dashboard, their referral code and
  materials; referrals are attributed but **cannot be paid**; creating, importing and exporting
  profiles are **blocked**.
- **KYC:** PAN (number + image) and bank account for payouts, stored on the private disk; reviewed
  in A07's KYC queue.
- **Suspended / deactivated:** Owner and all staff immediately lose create, import and export;
  every staff session is logged out live; existing attribution and history remain.

### B.4 Broker dashboard at a glance

| Widget | Shows | Visible to |
|---|---|---|
| Referrals | total, this month, converted, conversion rate | Owner, Manager |
| Managed profiles | total, draft, pending review, active, rejected, claimed | All (staff: own/assigned) |
| Moderation feed | latest approvals / rejections with reason (live) | All |
| Imports | running/ready batches with progress (live) | Owner, Manager, Data Entry |
| Client inbox | pending interests for clients | Owner, Manager, Telecaller |
| Follow-ups due today | from client notes | All |
| Commission | earned, pending, paid, next payout date | Owner only |
| Staff | seats used, active today | Owner only |

### B.5 Capability 1 — Referral program

```
Broker shares code BRK1042, link oppam.in/?ref=BRK1042, or QR poster
   ▼
Visitor lands with ?ref= → 30-day first-touch cookie
   ▼
Registers; code pre-filled (or typed at registration / wizard step 1)
   ▼
ResolveBrokerCode (uppercase + trim):
   FOUND + active   → profiles.broker_id = broker, broker_verified = true, broker_referrals PENDING
   FOUND + inactive → store broker_code_raw, broker_verified = false, flag in A07
   NOT FOUND        → store broker_code_raw, broker_verified = false, flag in A07
   (never blocks signup)
   ▼
Member later buys a plan → OrderPaid event
   ▼
commission_paise = order.subtotal_paise × broker.commission_bps / 10000   (pre-GST subtotal)
broker_referrals PENDING → EARNED; Owner notified live
   ▼
Monthly payout run (A07) → PAID      |   Refund → VOID + clawback on next run
```

- Inline validation on the wizard: `validate?code=` returns `{valid, name, district}` only — never
  the broker's phone or email.
- A typed code beats the `?ref=` cookie (more deliberate signal).
- Commission accrues only when the member's profile has reached `ACTIVE`.
- **Privacy:** the broker's referral list shows profile code, join date, status (joined /
  profile live / converted) and commission — never name, contact, photos, matches or messages.
- **Materials page:** code, copyable link, QR code (SVG/PNG), printable A4 poster PDF with the
  bureau name.

### B.6 Capability 2 — Managed profiles

**Create (single).** "Add profile" → one form with the same sections and validation as the M02
wizard (accordion: Basic, Education & career, Family, Partner preferences, Contact, Photos &
horoscope), autosaving a draft. Required client-consent checkbox: *"I confirm the client has agreed
to create this profile and to Oppam Matrimony's Terms & Privacy Policy."*

```
CreateManagedProfile (DB transaction):
   ├─ bureau active + KYC verified (else 403 BROKER_KYC_REQUIRED)
   ├─ staff permission profiles.create
   ├─ consent = true (else 422, nothing written); stamp broker_consent_confirmed_at,
   │    consent_confirmed_by_user_id (the individual staff user)
   ├─ client mobile must not exist on any user/profile (it is the future claim key)
   ├─ INSERT profiles { user_id NULL, owner_type BROKER_MANAGED, managed_by_broker_id,
   │    created_by_user_id, assigned_staff_id?, status DRAFT }
   ├─ INSERT wizard-step rows + photos (PENDING moderation)
   └─ completeness computed exactly as M02
Submit for review → PENDING_REVIEW → A04 queue with flag "Added by broker BRK1042"
   → approved: ACTIVE, searchable like any profile (nothing public reveals it is broker-entered)
   → rejected: reason shown to the bureau; edit & resubmit
```

**Manage.** Managed-profile list with status filter, search, assigned-staff filter (`#[Url]`);
per profile: edit any field (same validation), activate/deactivate (drops out of search like a
hidden profile), soft-delete (30-day recovery), assign to staff (single or bulk), notes, export.

**Client inbox.** Interests received by a managed profile appear in the bureau's Client Inbox;
Owner/Manager/Telecaller can **accept or decline on the client's behalf** (after speaking to the
family). Accept notifies the other member; **chat stays disabled** for unclaimed managed profiles —
the other member sees "This profile is managed by a registered bureau. They will contact you or you
can reach them after the client joins." Managed profiles can also **send** interests (counts
against the bureau's monthly allowance, default 100/month per bureau, set in A07).

**Client notes & reminders.** Per profile: note / call / meeting entries with outcome and next
follow-up date; due items appear on the dashboard and as a morning notification. Internal to the
bureau, never shown to members.

**Claim flow.**
```
Client registers normally with the SAME mobile (+ OTP)
   ▼
Registration finds an unclaimed BROKER_MANAGED profile with that contact mobile
   ▼
"We found a profile for this number: Anjali M. (OPM88213). Is this you?"
   ├─ Yes → ClaimManagedProfile (transaction): link user_id, owner_type SELF, claimed_at,
   │        clear managed_by_broker_id (old value kept in audit_logs), unassign staff
   │        → client now has full member access incl. chat; bureau loses ALL access instantly
   │        → Owner notified "Profile OPM88213 was claimed by the client"
   └─ No  → existing profile untouched; registration continues as a new profile
```
Commission still flows: if the claimed client later buys a plan, the bureau earns commission as a
managed-profile conversion.

### B.7 Capability 3 — Export & download center

| Export | Content | Format |
|---|---|---|
| **Biodata PDF** | The same data the profile owner sees on their own profile (never more): photo, basic, education & career, family, religious/horoscope, partner preferences, about. Bureau name, code and phone in the footer; watermark *"Prepared by <bureau>, Oppam Matrimony"* | A4 PDF (DomPDF), Malayalam-capable font |
| **Photo package** | The profile's **approved** photos, original resolution | ZIP |

```
ExportManagedProfile:
   ├─ profiles.managed_by_broker_id == caller's broker  (query-time WHERE, not a post-filter)
   │     NO → 403 PROFILE_NOT_MANAGED_BY_BROKER + security log
   ├─ staff permission profiles.export
   ├─ INSERT broker_profile_exports { broker_id, user_id (staff), profile_id, type }
   │     — on EVERY attempt, successful or not
   └─ stream file (controller route; Livewire only triggers it)
```

No export exists for any profile the bureau does not manage — not referred members, not search
results. There is no admin override in v1.

### B.8 Capability 4 — Staff accounts (new in v5)

- **Invite:** Owner enters name, mobile, role, profile scope (*all bureau profiles* / *only
  assigned*); staff receives an SMS link (72 h, single use) → sets password → verifies mobile by
  OTP → active.
- **Seat limit:** default **5 staff per bureau**, adjustable per broker by admin (A07). Deactivated
  staff free their seat.
- **Permissions:** role preset (B.2) with per-staff switch-offs by the Owner.
- **Assignment:** Owner/Manager assign managed profiles to staff (`assigned_staff_id`); bulk assign
  from the list; telecallers work from "My clients".
- **Deactivate / remove:** immediate — sessions revoked live (`ForceLogout`), assigned profiles
  return to the Owner's unassigned pool, history kept.
- **Activity log:** bureau-scoped view of `audit_logs` — who created, edited, imported, exported,
  accepted/declined what and when; filter by staff and date (Owner, Manager).
- **Performance report (Owner):** per staff per month — profiles created, imported, approved,
  rejection rate, interests handled, follow-ups completed.
- **Money stays with the bureau:** commission and payouts belong to the Owner only;
  `created_by_user_id` drives reports, never payouts.
- **One login, one bureau:** a mobile number can belong to only one bureau (as Owner or staff) at a
  time.

### B.9 Capability 5 — Bulk profile upload (new in v5)

**Files**
- **Template** `.xlsx` generated live from master data: a *Profiles* sheet (one row per client,
  columns in wizard order), reference sheets with valid values (religion, caste per religion, star,
  district, education, occupation, income band…) and drop-down validation, and an *Instructions*
  sheet. A `.csv` version is offered. Cells accept master-data **codes or exact labels**.
- **Required columns:** first name, last name, gender, DOB, height, marital status, religion,
  mother tongue, district/location, client mobile, `client_consent = YES`. All else optional.
- **Photos (optional):** a `.zip` whose file names are listed in the row's `photo_files` column
  (`anjali_1.jpg; anjali_2.jpg`; first = primary). Photos can also be added later per profile.
- **Limits:** 500 rows per file; sheet ≤ 10 MB; zip ≤ 200 MB (JPG/PNG/WebP/HEIC, each ≤ 10 MB);
  **1,000 imported rows/day** per bureau; new bureaus (< 30 days or < 20 moderated profiles)
  **100 rows/day** until their moderation approval rate is ≥ 80 %.

**Flow (live, no page reloads)**
```
1 Upload    /broker/import → sheet (+ zip) via Livewire WithFileUploads → private disk
            → batch B-0042 created (UPLOADED)
2 Validate  queued ParseImportBatch (Laravel Excel, chunks of 100) on the `imports` queue:
            ├─ auto-map headers (manual mapping screen if they differ)
            ├─ validate each row with the SAME rule set as the single form
            ├─ duplicates: mobile already on Oppam → ERROR; mobile repeated in file → ERROR;
            │   same name + DOB already in bureau → WARNING; same file hash before → banner
            ├─ photo names not found in zip → WARNING
            └─ progress broadcast on broker.{brokerId} (.import.progress) → live progress bar
            → READY
3 Preview   all rows with VALID / WARNING / ERROR badges, filter, click row for messages,
            fix cells inline, or download errors.xlsx, fix, and re-upload just those rows
4 Consent   batch attestation by the uploader (required) + every row must say YES
5 Import    queued ImportBatchRows, one transaction per row, same path as CreateManagedProfile
            (DRAFT, managed_by_broker_id, created_by_user_id, import_batch_id, consent stamps);
            photos through the normal media pipeline (PENDING) → COMPLETED + summary
6 Submit    "Submit all complete profiles" (or per profile) → PENDING_REVIEW → A04 queue,
            flagged "Added by broker BRK1042 · batch B-0042 · entered by <staff>"
```

**Batch history:** file, uploader, date, row counts (valid / warning / error / imported), status,
moderation approval rate; open a batch to see rows and resulting profiles; cancel before import;
download error report. Source files are purged from storage after **30 days**.

**Auto-pause:** if > **40 %** of a batch's submitted profiles are rejected at moderation, the
bureau's imports pause automatically and A07 is alerted (single-profile creation still works).

**Parsing edge cases:** Excel serial dates / `12-05-1998` / `1998/05/12` handled with explicit
formats (ambiguous → ERROR asking for DD-MM-YYYY); caste not belonging to religion → ERROR listing
valid options; height `5'4"`, `5.4`, `163` normalised to cm; Malayalam text accepted (UTF-8);
formula-like cells (`= + - @`) neutralised; uploader deactivated mid-import → job completes; KYC
revoked while READY → import disabled, batch can only be cancelled; interrupted upload → nothing
created before step 5.

### B.10 Commission & payouts

| Item | Rule |
|---|---|
| Base | Pre-GST order subtotal (`subtotal_paise`) |
| Rate | `brokers.commission_bps`, default 1000 (10 %), set by admin per broker (0–10000) |
| Triggers | The **first paid order** of a referred member or of a managed / claimed profile (one commission per profile), provided the profile reached `ACTIVE` |
| States | `PENDING` (attributed) → `EARNED` (order paid) → `PAID` (payout run) · `VOID` (refund → clawback on next run) |
| Payout cycle | Monthly run by finance in A07; balances < ₹500 roll forward; runs > ₹50,000 per broker need a **second approver**; row-locked approval; KYC required |
| Statement | Per-broker PDF statement: orders (referral and managed conversions), commission, clawbacks, TDS (if applicable), net paid, UTR reference |
| Visibility | Owner only (Earnings page + live "commission earned" toast on `broker-owner.{brokerId}`) |

### B.11 Broker portal — screens & Livewire components (`oppam.in/broker`)

| Route | Component | Purpose | Min. permission |
|---|---|---|---|
| `/broker/apply` | `Broker\Apply` | Public application form (name, bureau name, mobile + OTP, district, PAN) | public |
| `/broker` | `Broker\Dashboard` | Widgets in B.4, live | `bureau.dashboard.view` |
| `/broker/kyc` | `Broker\Kyc` | PAN + bank details, status | Owner |
| `/broker/profiles` | `Broker\ManagedProfiles\Index` | List, filters (`#[Url]`), bulk assign, actions | `profiles.view` |
| `/broker/profiles/create` | `Broker\ManagedProfiles\Form` | One-sitting creation, autosave, consent | `profiles.create` |
| `/broker/profiles/{code}` | `Broker\ManagedProfiles\Show` | Detail, edit, status actions, moderation reason, exports | `profiles.view` |
| `/broker/profiles/{code}/inbox` | `Broker\ManagedProfiles\ClientInbox` | Received interests, accept/decline; send interests | `client_inbox.respond` |
| `/broker/profiles/{code}/notes` | `Broker\ManagedProfiles\Notes` | Notes, calls, reminders | `client_notes.write` |
| `/broker/profiles/{code}/export/biodata` | controller | Streams PDF | `profiles.export` |
| `/broker/profiles/{code}/export/photos` | controller | Streams ZIP | `profiles.export` |
| `/broker/import` | `Broker\Import\Upload` | Template download, upload, column mapping | `profiles.import` |
| `/broker/import/template.xlsx` | controller | Generated template | `profiles.import` |
| `/broker/import/batches` | `Broker\Import\Batches` | Batch history | `profiles.import` |
| `/broker/import/{batch}` | `Broker\Import\BatchReview` | Live progress, preview, inline fix, consent, import, summary | `profiles.import` |
| `/broker/import/{batch}/errors.xlsx` | controller | Error report | `profiles.import` |
| `/broker/staff` | `Broker\Staff\Index` | Staff list, invite, seats | `staff.manage` |
| `/broker/staff/{id}` | `Broker\Staff\Show` | Permissions, assigned profiles, activity, performance | `staff.manage` |
| `/broker/staff/invite/{token}` | `Broker\Staff\AcceptInvite` | Signed invite acceptance | public (signed) |
| `/broker/activity` | `Broker\Activity` | Bureau activity log | Owner, Manager |
| `/broker/referrals` | `Broker\Referrals` | Own referrals only | `referrals.view` |
| `/broker/earnings` | `Broker\Earnings` | Ledger, payouts, statements | Owner |
| `/broker/materials` | `Broker\Materials` | Code, link, QR, poster | `materials.view` |

Layout: the member-site header/footer from the template with a **broker sidebar** (menu items
hidden per permission), bureau name + role chip, notification bell, live toasts. Fully responsive;
the managed-profile form and client inbox are designed for phone use in the field.

### B.12 Admin side — Broker Management (A07, `admin.oppam.in/brokers`)

**Permissions:** `brokers.view`, `brokers.edit`, `brokers.kyc`, `brokers.payout`,
`brokers.exports.view`, `brokers.staff.view`, `brokers.staff.edit`, `brokers.imports.view`,
`brokers.imports.manage`.

| Screen | Component | Features |
|---|---|---|
| Broker directory | `Admin\Brokers\BrokersIndex` | Code, name, phone, district, referrals (total/converted), managed profiles (total/active/claimed), conversion rate, commission (earned/pending/paid), KYC status, staff seats used, imports paused?, status; search & filters; create broker |
| Applications & KYC queue | `Admin\Brokers\KycQueue` | Self-applications and KYC submissions; view PAN (signed URL, audited); approve / reject with reason; KYC revocation |
| Broker detail | `Admin\Brokers\BrokerShow` | Tabs: **Overview** (edit commission, interest allowance, seat limit, caps; suspend/deactivate), **Referrals**, **Managed Profiles** (PENDING_REVIEW rows link into A04), **Staff** (list, deactivate, reset device/OTP; admins cannot add staff), **Imports** (batches, approval rate, pause/resume), **Earnings**, **Payouts**, **Exports**, **Disputes**, **Activity** (audit timeline) |
| Unverified codes | `Admin\Brokers\UnverifiedCodesQueue` | Mistyped/unknown codes with fuzzy suggestions (Levenshtein ≤ 2); link or clear; never auto-linked |
| Payout runs | `Admin\Brokers\PayoutsIndex` | Create run for a period, preview per broker (referral + managed conversions, clawbacks, roll-forwards), approve (second approver > ₹50k), mark paid with UTR, statements |
| Export audit | `Admin\Brokers\ExportsAudit` | Every export (broker, staff user, profile, type, time), filters, append-only; anomaly highlighting |
| Bulk import monitor | `Admin\Brokers\ImportsMonitor` | All batches across brokers, rejection rates, auto-paused review queue, source-file download (audited, until purge), pause/resume, change caps, cancel READY batch |
| Consent disputes | `Admin\Brokers\Disputes` | "I never agreed to this profile" complaints on managed profiles, linked A10 cases, outcome |

**Fraud & quality signals** (surfaced on the directory and A02 alerts)

| Signal | Response |
|---|---|
| Many referrals from one IP/device | Flag; hold commission |
| Referred accounts that never complete a profile | Excluded from commission |
| Referred accounts that pay then refund immediately | Clawback + investigation |
| Broker referring themselves under another identity | Suspend, void all |
| Referral spike far above the broker's history | Review before payout |
| Unusual export volume/pattern (e.g. same 40 PDFs twice in an hour) | Flag for follow-up (authorized but unusual) |
| High moderation-rejection rate on managed/imported profiles | Review data quality; auto-pause imports at 40 % |
| Imported mobiles reported "I never agreed" | Hide profile, A10 case, dispute count |
| Many imported profiles sharing a mobile/email pattern | Flag batch |
| Imported photos matching other profiles (pHash) | Flag batch, A04 escalation |
| Staff logging in from many devices/IPs; export spike by one staff user | Flag; Owner and admin notified |

**Admin rules:** R-A07-1 … R-A07-11 in B.16.

### B.13 Data model

```php
// Base (v4 §6.11)
Schema::create('brokers', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->string('code')->unique();                      // BRK1042 — system-generated
    $t->string('name');                                // Owner's name
    $t->string('bureau_name')->nullable();             // v5
    $t->string('phone');
    $t->string('email')->nullable();
    $t->string('district')->nullable();
    $t->unsignedSmallInteger('commission_bps')->default(1000);
    $t->enum('status', ['APPLIED','KYC_PENDING','ACTIVE','SUSPENDED','DEACTIVATED','REJECTED'])
      ->default('APPLIED');                            // v5 (v4 had is_active)
    $t->enum('kyc_status', ['NOT_SUBMITTED','PENDING','VERIFIED','REJECTED','REVOKED'])
      ->default('NOT_SUBMITTED');
    $t->unsignedTinyInteger('staff_seat_limit')->default(5);          // v5
    $t->unsignedSmallInteger('import_daily_cap')->default(1000);      // v5
    $t->unsignedSmallInteger('interest_allowance_monthly')->default(100); // v5, for managed profiles
    $t->timestamp('imports_paused_at')->nullable();                    // v5
    $t->timestamps();
});

Schema::create('broker_kyc', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('broker_id')->constrained('brokers');
    $t->string('pan_number_encrypted');                // encrypted cast
    $t->string('pan_image_path');                      // private disk
    $t->string('bank_account_encrypted');  $t->string('ifsc', 11);  $t->string('account_name');
    $t->enum('status', ['PENDING','VERIFIED','REJECTED']);
    $t->foreignUlid('reviewed_by_admin_id')->nullable();
    $t->string('rejection_reason')->nullable();
    $t->timestamps();
});

Schema::create('broker_referrals', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('broker_id')->constrained('brokers');
    $t->foreignUlid('profile_id')->unique()->constrained('profiles');   // one commission per profile
    $t->enum('source', ['REFERRAL','MANAGED'])->default('REFERRAL');   // v5: one ledger for both
    $t->foreignUlid('order_id')->nullable()->constrained('orders');
    $t->unsignedInteger('commission_paise')->default(0);
    $t->enum('status', ['PENDING','EARNED','PAID','VOID'])->default('PENDING');
    $t->foreignUlid('payout_item_id')->nullable();
    $t->timestamps();
    $t->index(['broker_id', 'status']);
});

// broker_payout_runs (period_start, period_end, status DRAFT/APPROVED/PAID, created_by, approved_by,
//   second_approved_by) and broker_payout_items (run_id, broker_id, gross, clawback, carried_forward,
//   net, utr, status) — as v4 A07.

// Profile columns (v4 §6.11a + v5)
Schema::table('profiles', function (Blueprint $t) {
    // user_id nullable (managed profiles have no login until claimed)
    // broker_id, broker_code_raw, broker_verified          — referral attribution
    // owner_type SELF|BROKER_MANAGED, managed_by_broker_id — ownership
    // broker_consent_confirmed_at, claimed_at
    $t->foreignUlid('consent_confirmed_by_user_id')->nullable()->constrained('users');   // v5
    $t->foreignUlid('created_by_user_id')->nullable()->constrained('users');             // v5
    $t->foreignUlid('assigned_staff_id')->nullable()->constrained('broker_staff');       // v5
    $t->foreignUlid('import_batch_id')->nullable()->constrained('broker_import_batches');// v5
    $t->index(['managed_by_broker_id', 'status']);
    $t->index(['managed_by_broker_id', 'assigned_staff_id']);
});

Schema::create('broker_profile_exports', function (Blueprint $t) {   // append-only
    $t->ulid('id')->primary();
    $t->foreignUlid('broker_id')->constrained('brokers');
    $t->foreignUlid('user_id')->constrained('users');                  // v5: which staff member
    $t->foreignUlid('profile_id')->constrained('profiles');
    $t->enum('export_type', ['BIODATA_PDF','PHOTO_PACKAGE']);
    $t->boolean('allowed');                                            // v5: denied attempts logged too
    $t->string('ip', 45)->nullable();
    $t->timestamp('created_at')->useCurrent();
    $t->index(['broker_id', 'created_at']);
    $t->index(['profile_id', 'created_at']);
});

// Staff (v5)
Schema::create('broker_staff', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('broker_id')->constrained('brokers');
    $t->foreignUlid('user_id')->unique()->constrained();   // one bureau per login
    $t->enum('role', ['OWNER','MANAGER','DATA_ENTRY','TELECALLER']);
    $t->enum('profile_scope', ['ALL','ASSIGNED'])->default('ALL');
    $t->json('disabled_permissions')->nullable();
    $t->enum('status', ['INVITED','ACTIVE','DEACTIVATED'])->default('INVITED');
    $t->foreignUlid('invited_by_user_id')->nullable()->constrained('users');
    $t->timestamp('last_login_at')->nullable();
    $t->timestamps();
    $t->index(['broker_id', 'status']);
});

Schema::create('broker_staff_invitations', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('broker_id')->constrained('brokers');
    $t->string('phone', 16);  $t->string('name');
    $t->enum('role', ['MANAGER','DATA_ENTRY','TELECALLER']);
    $t->enum('profile_scope', ['ALL','ASSIGNED']);
    $t->string('token_hash', 64)->unique();
    $t->timestamp('expires_at');  $t->timestamp('accepted_at')->nullable();
    $t->timestamps();
});

Schema::create('broker_client_notes', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
    $t->foreignUlid('broker_id')->constrained('brokers');
    $t->foreignUlid('author_user_id')->constrained('users');
    $t->enum('type', ['NOTE','CALL','MEETING']);
    $t->string('outcome', 40)->nullable();
    $t->text('body');
    $t->date('follow_up_on')->nullable()->index();
    $t->timestamp('follow_up_done_at')->nullable();
    $t->timestamps();
});

// Bulk import (v5)
Schema::create('broker_import_batches', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->string('code', 12)->unique();                  // B-0042
    $t->foreignUlid('broker_id')->constrained('brokers');
    $t->foreignUlid('uploaded_by_user_id')->constrained('users');
    $t->string('sheet_path');  $t->string('photos_zip_path')->nullable();   // private, purged at 30 d
    $t->char('file_sha256', 64)->index();
    $t->json('column_map')->nullable();
    $t->enum('status', ['UPLOADED','VALIDATING','READY','IMPORTING','COMPLETED','FAILED','CANCELLED']);
    $t->unsignedSmallInteger('rows_total')->default(0);
    $t->unsignedSmallInteger('rows_valid')->default(0);
    $t->unsignedSmallInteger('rows_warning')->default(0);
    $t->unsignedSmallInteger('rows_error')->default(0);
    $t->unsignedSmallInteger('rows_imported')->default(0);
    $t->timestamp('consent_attested_at')->nullable();
    $t->foreignUlid('consent_attested_by_user_id')->nullable()->constrained('users');
    $t->timestamp('files_purged_at')->nullable();
    $t->timestamps();
    $t->index(['broker_id', 'created_at']);
});

Schema::create('broker_import_rows', function (Blueprint $t) {
    $t->id();
    $t->foreignUlid('batch_id')->constrained('broker_import_batches')->cascadeOnDelete();
    $t->unsignedSmallInteger('row_number');
    $t->json('data');
    $t->enum('status', ['VALID','WARNING','ERROR','IMPORTED','SKIPPED']);
    $t->json('messages')->nullable();                  // [{field, level, text}]
    $t->foreignUlid('imported_profile_id')->nullable()->constrained('profiles');   // idempotency
    $t->timestamps();
    $t->unique(['batch_id', 'row_number']);
});
```

### B.14 Application layer — Actions

`Broker\ApplyAsBroker`, `ApproveBrokerKyc`, `ResolveBrokerCode`, `AttributeCommission`,
`VoidReferralOnRefund`, `CreateManagedProfile`, `UpdateManagedProfile`, `SubmitManagedProfile`,
`SetManagedProfileStatus`, `AssignManagedProfiles`, `RespondToInterestForClient`,
`SendInterestForClient`, `AddClientNote`, `ExportManagedProfile`, `ClaimManagedProfile`,
`InviteStaff`, `AcceptStaffInvite`, `UpdateStaffPermissions`, `DeactivateStaff`,
`Import\StoreImportBatch`, `Import\ParseImportBatch` (job), `Import\FixImportRow`,
`Import\AttestBatchConsent`, `Import\ImportBatchRows` (job), `Import\CancelImportBatch`,
`Payouts\CreatePayoutRun`, `Payouts\ApprovePayoutRun`, `Payouts\MarkPayoutPaid`,
`Safety\RecordConsentDispute`, `Safety\PauseBrokerImports`.
Services: `BiodataPdfRenderer`, `PhotoPackageBuilder`, `ImportTemplateBuilder`, `BureauGate`.

### B.15 Real-time events & notifications

| Event / notification | Channel | Recipients | UI effect |
|---|---|---|---|
| `ManagedProfileModerated` (approved / rejected + reason) | `broker.{brokerId}` | Owner, Manager, creator, assignee | Toast, dashboard feed, list row status |
| `ImportProgress` (`.import.progress`) | `broker.{brokerId}` | Uploader + Owner/Manager | Progress bar, row counts |
| `ImportCompleted` / `ImportsPaused` | `broker.{brokerId}` | Owner, Manager, uploader | Toast, banner |
| `ClientInterestReceived` | `broker.{brokerId}` | Owner, Manager, assigned Telecaller | Client-inbox badge, toast |
| `FollowUpDue` (08:30 daily) | notification | Note author / assignee | Bell + toast |
| `ProfileClaimed` | `broker.{brokerId}` | Owner, assignee | Toast; profile leaves the list |
| `CommissionEarned`, `PayoutPaid` | `broker-owner.{brokerId}` | Owner only | Toast, earnings refresh |
| `StaffDeactivated` → `ForceLogout` | `App.Models.User.{id}` | That staff member | Logged out in all tabs |
| `KycDecision`, `BrokerSuspended` | `broker-owner.{brokerId}` + SMS/email | Owner (suspension also to staff) | Banner, actions disabled live |

Channel authorization (`routes/channels.php`) checks the `broker_staff` row is ACTIVE and belongs
to that broker on every subscribe; `broker-owner.*` additionally requires `role = OWNER`.
Email/SMS: application received, KYC approved/rejected, staff invitation, payout paid, suspension.

### B.16 Business rules (consolidated)

**Broker portal — R-M13**

| # | Rule |
|---|---|
| R-M13-1 | An unknown or inactive referral code never blocks registration |
| R-M13-2 | Codes are upper-cased and trimmed before lookup |
| R-M13-3 | Referral attribution is first-touch and permanent; changes are admin-only and audited |
| R-M13-4 | Commission is on the pre-GST subtotal, for referral and managed-profile conversions alike |
| R-M13-5 | A refund voids the referral/conversion and reverses commission |
| R-M13-6 | A broker (and staff) see only their own bureau's referrals and managed profiles, scoped server-side, never via a client-supplied id |
| R-M13-7 | A broker never sees a referred self-registered member's contact details, photos, messages or matches |
| R-M13-8 | Inactive/suspended bureaus keep historical attribution but earn nothing new and cannot create, import or export |
| R-M13-9 | `?ref=BRK1042` sets a 30-day first-touch cookie; a typed code wins over the cookie |
| R-M13-10 | Creating managed profiles requires completed KYC (PAN) — handling someone else's data is the gate, not just payment |
| R-M13-11 | Every managed profile requires an explicit consent confirmation; the confirming user id and time are stored |
| R-M13-12 | A bureau may export only profiles where `managed_by_broker_id` is its own id — no exceptions, no admin override in v1 |
| R-M13-13 | Every export attempt is logged unconditionally |
| R-M13-14 | Managed and imported profiles go through the same A04 moderation as any profile — no auto-approval |
| R-M13-15 | Claiming requires registering with the exact stored mobile + OTP and an explicit confirm — never a silent link |
| R-M13-16 | Once claimed, the bureau loses all access to that profile permanently |
| ~~R-M13-17~~ | ~~No staff sub-accounts, no bulk import in v1~~ — **reversed in v5**; replaced by R-M13-18 … 33 |
| R-M13-18 | Staff act only inside their own bureau; every query is scoped by `broker_id` from the staff record |
| R-M13-19 | Staff permissions = role ceiling ∩ Owner's switch-offs, checked on every request |
| R-M13-20 | "Assigned only" staff get 404 for bureau profiles not assigned to them |
| R-M13-21 | If the bureau is inactive or KYC revoked, all staff lose create/import/export at the same moment |
| R-M13-22 | One mobile = one bureau login (Owner or staff); may hold a separate member account |
| R-M13-23 | Consent stamps and exports record the individual staff user id as well as the broker id |
| R-M13-24 | Only the Owner manages staff and sees earnings, bank details and KYC; ownership transfer is support-assisted and audited |
| R-M13-25 | Seat limit enforced at invite; deactivated staff free their seat |
| R-M13-26 | Bulk import requires an active, KYC-verified bureau and `profiles.import` |
| R-M13-27 | Every imported row needs `client_consent = YES` **and** the batch needs the uploader's attestation |
| R-M13-28 | Imported rows use the identical validation rules as the single form |
| R-M13-29 | A mobile already on Oppam can never be imported |
| R-M13-30 | Imported profiles are never auto-published |
| R-M13-31 | Row, daily and new-bureau caps are enforced server-side |
| R-M13-32 | > 40 % moderation rejections in a batch auto-pauses the bureau's imports |
| R-M13-33 | Import is idempotent per row (`imported_profile_id` guard) |
| R-M13-34 | Unclaimed managed profiles cannot chat; interests are handled by the bureau (client inbox) |
| R-M13-35 | Interests sent on behalf of managed profiles count against the bureau's monthly allowance |

**Broker admin — R-A07**

| # | Rule |
|---|---|
| R-A07-1 | Broker codes are system-generated, never admin input |
| R-A07-2 | KYC is required before payout **and** before managed-profile creation/import; cannot be bypassed by admin |
| R-A07-3 | Commission accrues only for profiles that reached `ACTIVE` |
| R-A07-4 | Payouts above ₹50,000 per broker require a second approver |
| R-A07-5 | Balances under ₹500 roll forward |
| R-A07-6 | Unverified codes are queued with fuzzy suggestions, never auto-linked |
| R-A07-7 | The export audit is append-only; nothing is ever deleted |
| R-A07-8 | Deactivating/suspending a bureau immediately blocks creation, import and export for Owner and all staff |
| R-A07-9 | Admin can pause a bureau's imports without deactivating it; single creation still works |
| R-A07-10 | A client "I never agreed to this profile" complaint hides the profile, opens an A10 case and counts as a dispute; 3 upheld disputes in 90 days suspends create/import rights pending review |
| R-A07-11 | Seat-limit and cap changes are audited; lowering the seat limit never auto-deactivates staff |

### B.17 Validation

- Referral code: ≤ 20 chars, alphanumeric, normalised server-side.
- Managed-profile form and every imported row: the **same Form Request / rule set as M02**
  (age 18+ F / 21+ M, required gender, height, marital status, religion, mother tongue, location;
  caste must belong to religion), plus client mobile required, E.164-normalised, unique across
  Oppam.
- Consent: required boolean `true` (form) / `YES` (row) + batch attestation; 422 before any write.
- KYC gate: `brokers.kyc_status = VERIFIED` and `status = ACTIVE` else 403 `BROKER_KYC_REQUIRED` /
  `BROKER_INACTIVE`.
- Staff invite: mobile not already a bureau login; role ∈ presets; seat available.
- Import files: extension and MIME sniffed (xlsx/csv/zip), size limits, row limit, zip entries
  whitelisted by extension, no nested archives, zip-slip path checks.
- Admin: commission 0–10000 bps; payout `period_start < period_end`; re-running a paid period
  needs an audited override; export-audit filters must resolve to existing rows.

### B.18 Security & privacy

- **IDOR guard everywhere:** every bureau query starts from `where managed_by_broker_id = <caller's
  broker>` (and `assigned_staff_id` for assigned-only staff); list/detail of a foreign profile →
  404, export → 403 + log. Livewire id properties are `#[Locked]`.
- **Export is the highest-risk surface:** ownership check + permission + unconditional log +
  watermark with bureau name (traceable leaks).
- **KYC before data entry**, consent at creation and import (DPDP Act: a third party entering
  someone's personal data must attest consent), consent-dispute handling (R-A07-10).
- **PAN, bank details and import files** on the private disk, encrypted; import files purged at 30
  days; PAN images viewed by admins through signed, audited URLs only.
- **Claim is explicit and OTP-protected** — knowing a managed profile's phone number is not enough.
- **Staff:** individual logins (no password sharing), OTP on new devices for Owner/Manager, live
  session revocation on deactivation, per-action audit with the individual user id.
- **Spreadsheet safety:** formula injection neutralised on import and on every generated
  spreadsheet (errors.xlsx, admin exports).
- **Live checks, no caching** of bureau status, KYC or staff permissions past a change.
- The referral validate endpoint returns name and district only.

### B.19 Edge cases

| Case | Handling |
|---|---|
| `brk1042`, `BRK 1042` | Normalised before lookup |
| Code belongs to a deactivated broker | Flagged; no commission |
| Member refunds after commission was paid | `VOID`, negative adjustment on next payout |
| Create before KYC | 403 `BROKER_KYC_REQUIRED`, nothing written |
| Consent missing | 422, nothing written |
| One family phone used for two managed profiles | Second profile needs a different contact mobile (mobile is unique); genuine cases resolved via support |
| Claimed profile, old bureau opens it | 404; no export option; access gone server-side |
| Export racing a claim | Ownership re-read inside the claim transaction boundary — no window where both can export |
| Managed profile rejected at moderation | Bureau notified with reason, edit & resubmit |
| Managed profile deleted, then client tries to claim | Claim not offered; client registers fresh |
| Staff member deactivated while editing | Next Livewire request → logged out; unsaved changes lost (autosave draft kept) |
| Owner leaves the business | Support-assisted ownership transfer, audited; KYC redone for the new Owner |
| Seat limit lowered below active staff | No auto-deactivation; Owner prompted to choose |
| Two admins approve the same payout | Row lock; second sees "already approved" |
| KYC revoked later | Existing profiles and referrals untouched; new create/import/export blocked immediately |
| Export-audit query for a deactivated broker | Full history still returned |

### B.20 End-to-end flows

**F08 — Managed profile to claimed member.** Bureau KYC approved (A07) → staff creates managed
profile with consent → A04 moderation with broker flag → approved → bureau live notification →
client interests handled in the Client Inbox → client registers with the same mobile + OTP → "Is
this you?" → confirm → profile becomes SELF, bureau access revoked, chat unlocks → client upgrades
→ commission EARNED → monthly payout (second approver > ₹50k) → PAID.

**F09 — Bureau onboarding with staff and bulk upload.** Broker applies → admin approves KYC →
broker becomes Owner → invites Manager, Data Entry and Telecaller by SMS → Data Entry downloads the
template, fills 150 rows (+ photo zip), uploads → live validation → fixes 12 ERROR rows inline,
accepts 3 WARNING rows → consent attestation → import → 150 DRAFT profiles → "Submit all complete"
→ A04 reviews batch `B-0042` → approvals stream back live → Owner assigns profiles to the
Telecaller → Telecaller handles client interests and logs follow-ups → clients claim over time
(F08) → commission to the Owner. If > 40 % of the batch is rejected → imports auto-paused, A07
review.

**F10 — Referral to payout.** Broker shares QR at a community event → visitor registers with
`?ref=` cookie → profile approved → buys Gold (₹11,988 + GST) → commission ₹1,198.80 EARNED
(10 % of pre-GST subtotal) → payout run → statement → PAID with UTR.

### B.21 Acceptance criteria

**Referral & commission**
- [ ] An unknown code registers the member and flags the code; a valid code creates a PENDING referral.
- [ ] Commission is computed on the pre-GST subtotal; a refund voids it and claws it back.
- [ ] `?ref=` sets a 30-day cookie honoured at registration; the validate endpoint returns name and district only.
- [ ] Changing attribution writes an audit row naming the admin.

**Managed profiles, exports & claim**
- [ ] No KYC → cannot create or import (403); no consent → 422, nothing saved.
- [ ] Managed and imported profiles go through A04 exactly like self-registered ones.
- [ ] Exporting a profile the bureau doesn't manage → 403, and a `broker_profile_exports` row is written for every attempt.
- [ ] Claim requires an explicit confirm and immediately revokes the bureau's access.
- [ ] Unclaimed managed profiles cannot chat; the bureau can accept/decline interests for them.

**Staff**
- [ ] The Owner can invite up to the seat limit; the next invite is refused.
- [ ] A Telecaller gets 403 on import, export, earnings and staff pages even by typing the URL.
- [ ] "Assigned only" staff get 404 for unassigned profiles.
- [ ] Deactivating a staff member logs them out of every tab within 2 s.
- [ ] Exports and consent stamps record the individual staff user id.

**Bulk upload**
- [ ] A 500-row sheet validates with a live progress bar and a VALID/WARNING/ERROR preview.
- [ ] Rows with a mobile already on Oppam, or without consent, are never imported.
- [ ] Imported profiles are DRAFT until submitted, then appear in A04 with the batch flag.
- [ ] Re-running an import job creates no duplicates; formula cells are neutralised.
- [ ] > 40 % rejections in a batch auto-pause imports and alert A07.

**Admin**
- [ ] Broker codes are generated, never typed; unverified codes show fuzzy suggestions.
- [ ] Payouts > ₹50,000 need a second approver; balances < ₹500 roll forward; KYC-less bureaus are excluded.
- [ ] The export audit lists every export and can't be edited or deleted.
- [ ] Deactivating a bureau blocks Owner and staff at once and logs staff out live.
- [ ] Pausing imports leaves single-profile creation working.

### B.22 Tests (release-blocking marked ★)

- ★ `IdorScopingTest` — foreign broker ids/profile codes in every broker route and Livewire action
  return only the caller's rows / 404.
- ★ `ExportAuthorizationTest` — 403 for non-managed profiles and a log row on every attempt.
- ★ `BureauPermissionMatrixTest` — every role × every route/action in B.11.
- ★ `ImportValidationParityTest` — the import validator and the single-profile form use the same rule set.
- `CommissionCalculationTest`, `RefundClawbackTest`, `PayoutRunTest` (second approver, roll-forward, row lock).
- `ClaimFlowTest` (explicit confirm, access revocation, export race).
- `StaffLifecycleTest` (invite, accept, seat limit, deactivate → ForceLogout broadcast).
- `ImportPipelineTest` (duplicates, consent, caps, idempotent re-run, formula neutralisation, 500-row time budget, auto-pause).
- `BrokerChannelAuthTest` (`broker.*` and `broker-owner.*` subscriptions).
- Dusk: bureau onboarding → staff invite → import → moderation approval appears live.

### B.23 Configuration & feature flags

Feature flags (A15): `broker.self_apply`, `broker.staff`, `broker.bulk_import`,
`broker.client_interest_sending`. Editable defaults (A07 per broker / A15 global): commission rate
10 %, staff seats 5, import 500 rows/file · 1,000/day · 100/day for new bureaus, auto-pause
threshold 40 %, managed-profile interest allowance 100/month, payout minimum ₹500, second-approver
threshold ₹50,000, import file retention 30 days. Horizon queue: `imports`. Scheduled jobs:
import-file purge (daily), follow-up reminders (08:30), commission maturation, payout-run reminders.

### B.24 Build plan & open decisions

Built in **Phase 7 (weeks 16–18)** of §18, after moderation (A04), media, payments and the audit
log (A12) exist. Order inside the phase: bureau auth & staff model → managed profiles & client
inbox → exports → bulk import → admin A07 screens & payouts → tests.

| Open decision | Proposed default |
|---|---|
| Staff seats free or part of a paid bureau plan? | 5 free seats; more granted by admin in v1; paid bureau plans in v2 |
| Managers can export by default? | Yes; Owner can switch off; Data Entry and Telecaller never |
| Import limits | 500 rows/file, 1,000/day, 100/day for new bureaus |
| Can bureaus send interests for managed profiles? | Yes, 100/month per bureau (flag `broker.client_interest_sending`) |
| Chat for unclaimed managed profiles | Disabled in v1 |
| Brokers self-apply or invite-only? | Self-apply with admin KYC approval |

**v2 candidates:** multi-branch bureaus (one Owner, several offices), custom staff roles, paid
bureau subscription plans, bureau-branded public profile page, broker mobile app, WhatsApp biodata
sharing from the portal, assisted-service relationship managers built on this model.

---

## 12. Cross-Module Flows (end-to-end "entire working")

### F01 — Signup to live profile
```
Home hero QuickRegister ─► OTP verify (M01) ─► Wizard steps 1–6 with autosave (M02)
 ─► status PENDING_REVIEW ─► AdminQueueCountChanged → A04 badge +1 (live)
 ─► Moderator approves (A04) ─► status ACTIVE, published_at, profile searchable
 ─► ProfileApproved notification: bell + toast (live) + SMS/email
 ─► Match generation job for this member (F06) ─► dashboard shows first matches
 ─► Nudges: verify ID (M09), upgrade (M10); incomplete-wizard journeys stop (A14)
Rejected ─► reason on dashboard + "Edit & resubmit" deep link ─► back to PENDING_REVIEW
```

### F02 — Like → Mutual → Interest → Chat
```
A likes B (LikeButton) ─► ToggleLike: limit check, insert likes ─► ProfileLiked to B (live: bell,
  "Liked me" list prepend, dashboard strip)
B likes A back ─► is_mutual ─► both receive "It's a match!" (live modal/toast) with "Send interest"
A sends interest (quota −1) ─► InterestReceived to B (live + email + SMS)
B accepts ─► interests.status ACCEPTED ─► conversation created (+ SYSTEM message)
  ─► InterestAccepted to A (live) ─► "Chat" button enabled on both profile pages & lists (live)
A (paid) opens /messages/{id} ─► real-time chat (F03)
B declines ─► polite notification to A, 90-day cooldown
No response in 30 days ─► EXPIRED, quota not refunded (reminder at day 3 via A14 journey)
```

### F03 — Real-time chat message lifecycle
```
Sender composer ─► optimistic bubble (Alpine) ─► Livewire send(clientId)
 ─► SendMessage: authorize, entitlement, rate-limit, ContentSafety, insert, counters
 ─► commit ─► MessageSent on chat.{c} (toOthers) + ConversationUpdated on inbox.{recipient}
Recipient thread open ─► onMessage → append → MarkConversationRead → MessagesRead → sender ✓✓ blue
Recipient on other page ─► ChatBadge +1, toast, tab title "(1)"
Recipient offline ─► after 10 min: NewMessage email digest (if enabled)
Flagged content ─► masked for recipient, A13 queue +1 (live)
Reconnect ─► loadSince(lastId) back-fill
```

### F04 — Payment & entitlement activation
```
/plans ─► /checkout/{plan}?duration=12 ─► coupon validate (live) ─► server creates order
  (amount from plan key + coupon + GST) ─► Razorpay order ─► Razorpay Checkout
Webhook payment.captured (signature verified, idempotent) ─► DB transaction:
  order PAID, payment row, subscription ACTIVE, entitlement counters set, invoice
 ─► EntitlementsChanged (live: composer unlocks, quotas refresh, Premium badge)
 ─► payment_success notification + invoice email
 ─► OrderPaid event ─► broker commission EARNED if referred/managed (M13) ─► broker live toast
Client redirect ─► /billing/success polls order status until PAID (never trusts redirect alone)
```

### F05 — Verification
Member uploads ID (private disk, encrypted) → A05 queue (+1 live) → officer claims → views via
10-min signed URL (audited) → approve → Verified badge (live) + notification → originals purged
after 90 days.

### F06 — Daily matches (05:00 IST)
Scheduler → chunked job per 1,000 members on `matching` queue → candidate filter → score →
diversity → exclude blocked/ignored/interacted → persist → `daily_matches_ready` notification
(in-app + email per prefs) → dashboard slider + countdown.

### F07 — Report → moderation → action
Member reports profile/message (M09) → A10 case (triaged P0/P1/P2) or A13 flagged queue → reviewer
reveals evidence (audited) → action (warn/remove/freeze/suspend/ban) → live effects (ForceLogout,
ConversationFrozen, profile removed from search) → reporter gets "reviewed and closed".

### F08 – F10 — Broker flows
See **§11A — B.20** (managed profile to claimed member, bureau onboarding with staff and bulk
upload, referral to payout).

---

## 13. Route Index (abridged)

**Public (`routes/web.php`)**: `/`, `/login`, `/register`, `/verify-otp`, `/forgot-password`,
`/claim-profile`, `/about`, `/branches`, `/success-stories`, `/plans`, `/contact`, `/privacy`,
`/terms`, `/safety`, community landing pages, `/sitemap.xml`. Legacy `*.php` → 301.

**Member (`auth`, `verified.phone`, `profile.onboarded` except onboarding)**: `/onboarding/{step}`,
`/dashboard`, `/me`, `/profile/{code}`, `/profiles`, `/search`, `/matches`, `/matches/daily`,
`/likes`, `/favorites`, `/interests`, `/visitors`, `/messages`, `/messages/{conversation}`,
`/notifications`, `/verify`, `/settings/{section}`, `/checkout/{plan}`, `/billing`,
`/billing/invoices/{invoice}` (controller download), `/stories/submit`.

**Broker (`auth`, `role:BROKER`, `broker.active`)**: `/broker`, `/broker/apply`, `/broker/kyc`,
`/broker/profiles`, `/broker/profiles/create`, `/broker/profiles/{code}`,
`/broker/profiles/{code}/inbox`, `/broker/profiles/{code}/export/biodata` (controller, streams PDF),
`/broker/profiles/{code}/export/photos` (controller, streams zip), `/broker/profiles/{code}/notes`,
`/broker/import`, `/broker/import/template.xlsx` (controller, generated from master data),
`/broker/import/batches`, `/broker/import/{batch}`, `/broker/import/{batch}/errors.xlsx`
(controller), `/broker/staff`, `/broker/staff/{id}`, `/broker/staff/invite/{token}` (public,
signed), `/broker/activity`, `/broker/referrals`, `/broker/earnings`, `/broker/materials`.

**Webhooks (`api` middleware, no CSRF, signature-verified)**: `POST /webhooks/razorpay`,
`POST /webhooks/msg91/dlr`, `POST /webhooks/mail/bounce`.

**Broadcasting**: `POST /broadcasting/auth` (member/broker), `POST admin.oppam.in/broadcasting/auth`
(admin).

**Admin (`admin.oppam.in`)**: `/login`, `/two-factor`, `/`, `/members`, `/members/{id}`,
`/moderation/{profiles|photos|edits|escalations}`, `/verification`, `/safety/cases`,
`/safety/chats`, `/billing/{plans|orders|stuck|subscriptions|refunds|coupons|invoices}`,
`/brokers`, `/brokers/{id}`, `/brokers/{kyc|unverified-codes|payouts|exports}`,
`/content/{...}`, `/comms/{templates|announcements|campaigns|journeys|log}`, `/reports/{key}`,
`/support/tickets`, `/masters/{list}`, `/audit`, `/system`, `/settings/{section}`, `/staff`,
`/roles`, `/horizon`, `/pulse`.

---

## 14. Non-Functional Requirements

| Area | Requirement |
|---|---|
| **Performance** | TTFB < 400 ms p95 for member pages; Livewire interactions < 300 ms p95 server time; search < 800 ms p95 @ 100k profiles; LCP < 2.5 s on 4G (template images already optimised; keep width/height + lazy loading) |
| **Real-time latency** | Message delivery < 1 s p95; notification < 2 s p95; typing < 500 ms |
| **Capacity (year 1)** | 200k profiles, 20k DAU, 5k concurrent WebSocket connections, 500k messages/day |
| **Availability** | 99.9 % monthly for web; chat degrades to polling if Reverb is down (never loses messages) |
| **Security** | OWASP ASVS L2; CSRF automatic (Livewire); `#[Locked]` on all id properties in Livewire; policies on every model action; rate limits on auth, likes, interests, messages, search; security headers from template `.htaccess` ported to Nginx; ID docs encrypted at rest on private storage; secrets never in DB; dependency scanning in CI |
| **Privacy / compliance** | India **DPDP Act 2023**: consent at signup and for broker-entered profiles, purpose limitation, data-access & erasure requests (M14), breach notification runbook; data residency in India (AWS Mumbai / equivalent); IT Act intermediary rules (grievance officer page) |
| **Accessibility** | WCAG 2.1 AA — carry over template rules (contrast tokens, one `<h1>`, labels, skip link, focus trap in drawer); live regions (`aria-live="polite"`) for new chat messages and toasts |
| **SEO** | Server-rendered public pages, canonical URLs, sitemap, structured data (Organization, FAQ) |
| **Browser support** | Last 2 versions of Chrome, Safari (iOS 15+), Firefox, Edge, Samsung Internet |
| **Localisation** | English v1; all strings through `__()` so Malayalam (v2) is a translation job; dates in IST; currency INR |
| **Observability** | Sentry (errors, with user code not PII), Pulse, Horizon, structured logs, uptime checks on web + `wss://` |
| **Backups** | MySQL daily full + binlog PITR (7 days), S3 versioning; quarterly restore drill |

---

## 15. Environment & Configuration (`.env.example` excerpt)

```dotenv
APP_NAME="Oppam Matrimony"
APP_URL=https://oppam.in
ADMIN_DOMAIN=admin.oppam.in
APP_INDEXABLE=false                 # replaces template SITE_LIVE

DB_CONNECTION=mysql
DB_DATABASE=oppam
REDIS_HOST=127.0.0.1
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# ── Broadcasting: reverb (self-hosted) OR pusher (managed) ──
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=oppam
REVERB_APP_KEY=change-me
REVERB_APP_SECRET=change-me
REVERB_HOST=ws.oppam.in
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SCALING_ENABLED=true

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=ap2              # Mumbai

VITE_BROADCAST_DRIVER="${BROADCAST_CONNECTION}"
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"

RAZORPAY_KEY_ID=
RAZORPAY_KEY_SECRET=
RAZORPAY_WEBHOOK_SECRET=
MSG91_AUTH_KEY=
MSG91_SENDER_ID=OPPAMM
MSG91_OTP_TEMPLATE_ID=
MAIL_MAILER=ses
FILESYSTEM_DISK=s3
AWS_BUCKET_PUBLIC=oppam-public
AWS_BUCKET_PRIVATE=oppam-private
SENTRY_LARAVEL_DSN=
```

Local development: `php artisan reverb:start --debug`, `php artisan horizon`,
`php artisan schedule:work`, `npm run dev` (or `composer run dev` running all four). XAMPP is not
used for the Laravel app; use Laravel Herd / Sail / Docker. Seeders create master data (v4
Appendix B), 3 plans, 200 demo profiles from the template's demo members, 3 brokers, and one admin
per role.

---

## 16. Testing Strategy

| Level | Tooling | Must cover |
|---|---|---|
| Unit | Pest | Match scoring, entitlement calculator, ContentSafety patterns, commission maths (pre-GST), age rules |
| Feature | Pest + `Livewire::test()` | Every Action; every Livewire component's authorization (`#[Locked]` tamper tests); IDOR tests (foreign ids → 404/403); quotas; interest state machine; webhook idempotency; broker R-M13-6/12 tests (release-blocking, from v4); **bureau staff permission matrix** (every role × every broker route/action, including URL-typing attempts); staff scope (assigned-only → 404); cross-bureau isolation; **bulk import**: validation parity with the single form (same rule set asserted), duplicate-mobile rejection, consent required, caps, idempotent re-run, formula-injection neutralisation, 500-row file within time budget |
| Broadcasting | `Event::fake()` / `Broadcast` assertions, channel auth tests | Correct channel & payload per event; blocked pairs never receive; `channels.php` denies non-participants |
| Browser / E2E | Laravel Dusk with a real Reverb in CI | Two browsers: like → mutual → interest → accept → chat round-trip with typing and read receipts, no reload; notification bell live update; reconnect back-fill; payment happy path with Razorpay test mode |
| Load | k6 (HTTP) + k6 WebSocket / `artillery` | 5k concurrent sockets, 200 msgs/s, search at 100k profiles |
| Security | Larastan level 6, `composer audit`, OWASP ZAP baseline on staging | |
| Accessibility | axe-core in Dusk on key pages | |

CI (GitHub Actions): lint (Pint), static analysis, tests with MySQL + Redis + Reverb services,
build assets; merges blocked on failure; coverage ≥ 80 % for `app/Actions`.

---

## 17. Deployment Runbook (summary)

- **Topology:** Nginx + PHP-FPM app servers (≥ 2) behind a load balancer; **Reverb server(s)** on
  separate process/host (`ws.oppam.in`, Nginx WebSocket proxy, sticky sessions not required with
  Redis scaling); Horizon workers; one scheduler (`schedule:run` via cron, `onOneServer()`); MySQL
  8 managed (primary + read replica); Redis managed; S3 + CloudFront; Cloudflare in front (WAF,
  admin IP allowlist).
- **Process supervision (Supervisor/systemd):** `reverb:start`, `horizon`, and restart on deploy
  (`reverb:restart`, `horizon:terminate`).
- **Nginx for Reverb:**
  ```nginx
  location / {
      proxy_http_version 1.1;
      proxy_set_header Host $http_host;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection "Upgrade";
      proxy_read_timeout 120s;
      proxy_pass http://127.0.0.1:8080;
  }
  ```
- **Deploy steps:** zero-downtime (Envoyer/Forge/Deployer): install, `npm ci && npm run build`,
  `php artisan migrate --force` (expand/contract), `optimize`, `view:cache`, `event:cache`,
  restart Horizon & Reverb, smoke test (login, search, send test message between two test accounts).
- **Scheduled jobs:** daily matches 05:00; interest expiry hourly; plan expiry reminders 09:00;
  saved-search alerts 07:00; digest emails; notification pruning; document purge; broker import-file purge (30 d); staff follow-up reminders 08:30; reports snapshot
  01:00; reconciliation 02:00; broker commission maturation; inactive-profile auto-hide; sitemap.
- **Switching to Pusher:** set `BROADCAST_CONNECTION=pusher` + keys, rebuild assets, stop Reverb.
  Pusher plan must cover concurrent connections and messages/day in §14.

---

## 18. Phased Build Plan (≈ 21 weeks, 3–4 developers)

| Phase | Weeks | Deliverables |
|---|---|---|
| 0 Foundation | 1–2 | Laravel 12 skeleton, Vite + template CSS/JS port, layouts (public/member/broker/admin), Blade components from template partials, CI, environments, master-data seeders, admin auth + 2FA + RBAC (A01) |
| 1 Identity & profiles | 3–5 | M01 (OTP, register, login, claim hook), M02 wizard, M11 photos, M03 profile views, A04 moderation, A03 members (basic), A11 master data |
| 2 Discovery | 6–7 | M04 search & saved searches, M05 dashboard/matches/daily job, M15 visitors |
| 3 Engagement & real-time core | 8–10 | Reverb + Echo setup, channels, presence; M06 likes/favorites/interests; M08 notifications (bell, toasts, page, preferences); A02 live dashboard |
| 4 Chat | 11–12 | M07 real-time chat (optimistic send, receipts, typing, back-fill, safety scanning), A13 chat safety |
| 5 Money | 13–14 | M10 Razorpay checkout, webhooks, invoices, entitlements live; A06 billing, coupons, refunds |
| 6 Trust | 15 | M09 verification & reporting, A05 queue, A10 abuse & support |
| 7 Broker | 16–18 | M13 broker portal (referral, KYC, managed profiles, client inbox, exports, claim flow), **bureau staff accounts & permissions, client notes/reminders, bulk Excel/CSV + photo import**, A07 broker management, staff tab, import monitor & payouts |
| 8 Content, comms, settings | 19 | M12 CMS-driven pages, M14 settings & privacy, M16 stories, A08, A14, A15 |
| 9 Hardening & launch | 20–21 | A09 reports, A12 audit & health, load tests (5k sockets), security review, accessibility audit, data migration/seed, `APP_INDEXABLE=true`, launch |

Non-negotiable ordering: auth & RBAC before anything with data; moderation before search goes
public; real-time foundation (Phase 3) before chat; payments before any entitlement-gated feature
is switched on in production; broker exports only after the audit log (A12) exists.

---

## 19. Open Questions & Decisions Needed

| # | Question | Proposed default |
|---|---|---|
| 1 | Free members: allow **one reply per conversation** (R-M07-3) or strictly read-only (v4)? | One free reply — reduces "pay to say hello" churn; configurable in A15 |
| 2 | Chat for **unclaimed broker-managed profiles** — disabled, or broker chats on the client's behalf? | Disabled in v1; interests handled by broker (M13 v5 addition) |
| 3 | Reverb self-hosted vs Pusher Channels from day one? | Reverb (cost, data stays in India); Pusher as fallback via env switch |
| 4 | Should **porutham (star) matching** move into v1 given Kerala expectations? | Keep v2 (v4 decision), but collect star/rasi/dosham in v1 so it can switch on later |
| 5 | Photo visibility default: All members vs On request? | All members (better engagement); women can change in one tap during wizard step 6 |
| 6 | Voice/video calls? | v2, masked WebRTC, premium initiates |
| 7 | Brand domain & final plan durations/prices (3/6/12-month discounts)? | Business decision |
| 8 | Brokers self-apply publicly or invite-only? | Self-apply behind `broker.self_apply` flag, admin KYC approval required |
| 9 | Default **staff seat limit** per bureau — free, or a paid bureau plan for more seats? | 5 free seats; more seats granted by admin in v1; paid bureau plans considered for v2 |
| 10 | May a **Manager** export biodata by default? | Yes, Owner can switch it off; Data Entry and Telecaller never |
| 11 | Bulk import limits (500 rows/file, 1,000/day, 100/day for new brokers)? | As proposed; adjustable per broker in A07 |

---

## Sources (competitor research, September 2026)

- [KeralaMatrimony — home](https://www.keralamatrimony.com/) · [Assisted Service](https://www.keralamatrimony.com/assisted/) · [App Store listing](https://apps.apple.com/in/app/kerala-matrimony-wedding-app/id1044248685)
- [Kerala Matrimony Sites Compared 2026 (isht.am)](https://isht.am/blog/kerala-matrimony-sites-compared-2026)
- [Kerala Matrimony Review 2026 (DatingScout)](https://www.datingscout.com/kerala-matrimony/review)
- [Shaadi.com membership plans](https://www.shaadi.com/info/introduction/membership-plans) · [Shaadi Meet](https://support.shaadi.com/support/solutions/articles/48001159195-what-is-shaadi-meet-how-does-it-work-) · [Premium benefits](https://support.shaadi.com/support/solutions/articles/48000953202-what-additional-benefits-do-i-get-as-a-premium-member-) · [Kundali matching](https://www.shaadi.com/horoscope-compatibility/kundali-matching)
- [Jeevansathi privacy features](https://www.jeevansathi.com/privacy-features)
- [BharatMatrimony FAQ](https://www.bharatmatrimony.com/faq.php) · [BharatMatrimony privacy & security](https://bharatmatrimony.com/privacy-security.php?gaact=HP&gasrc=FTRSAFETIPSORIYA)
- [M4Marry](https://www.m4marry.com/) · [Chavara Matrimony](https://www.chavaramatrimony.com/)
- [10 Porutham for marriage (Prokerala)](https://www.prokerala.com/astrology/porutham/10-porutham-for-marriage.htm)

*End of document.*
