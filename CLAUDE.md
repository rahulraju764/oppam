# Oppam Matrimony — Laravel application

Kerala-focused matrimony platform: member site, broker/bureau portal and admin panel in **one
Laravel application**. The full specification is the PRD at
`docs/Oppam_Matrimony_PRD_v5_Laravel_Livewire_Realtime.md` — it is the source of truth for every
feature, rule, table and screen. The original PHP/Bootstrap template (visual source of truth) is in
`docs/template/`, with its engineering notes in `docs/template-notes.md`.

## Project skills & agents — use them, every session

This project ships its own skills and review agent in `.claude/` (project-only, not global).
This file is the summary; the skills hold the deep rules. If a skill and this file disagree,
stop, tell the user, and fix the one that is wrong.

### Skills (`.claude/skills/`)

| Skill | What it contains | Load it when |
|---|---|---|
| **`oppam-code-standards`** | 10 core rules, "where does this code go" table; references: `project-structure.md` (full folder tree, domain names, naming, growth limits), `clean-code.md` (PHP style, errors, logging), `laravel-patterns.md` (Action / DTO / Policy / Service / Event / Job / Notification templates, migration & DB standards, validation, config), `livewire-realtime.md` (Livewire rules, Reverb/Echo contract) | **Before writing or changing any** PHP, Blade, Livewire, migration, route or JS, and when deciding where new code belongs |
| **`oppam-testing`** | Testing pyramid (unit, feature, Livewire, browser/Dusk), the **security test matrix** (guest, wrong user, suspended, blocked, unverified, missing, tampered id, invalid input, rate limit), real-time/broadcast tests, factories, rules | Writing or reviewing tests; finishing any feature |
| **`oppam-ui-standards`** | Bootstrap 5.3 + design-token system (no Tailwind), Blade UI component kit, mandatory UI states (loading, empty, error, success, disabled, reconnecting), 320/375/768/1024/1440 px checks, accessibility, Alpine rules | Creating or changing any view, CSS or Alpine code |
| **`oppam-review-gate`** | Gate procedure; Gate A AI-agent checklist, Gate B security, Gate C performance, Gate D UI; **Never allow** list; the **required completion report** format | Before saying anything is done, before any commit, when reviewing a diff or AI-generated code |

### Agents

| Agent | Where | What it does | How to run it |
|---|---|---|---|
| **`oppam-reviewer`** | `.claude/agents/oppam-reviewer.md` (project) | Independent, **read-only** reviewer: reads the diff, runs pint / phpstan / pest (/ dusk), walks Gates A–D, checks the session's "Done when", returns findings (Blocker / Major / Minor), the completion report and a verdict **READY TO COMMIT** or **NOT READY**. Never edits code. | `Use the oppam-reviewer agent to review the current changes.` (check it's loaded with `/agents`) |
| `quality-gate` | user-level (`~/.claude/agents`) | The user's general 13-stage quality gate; loads these project skills automatically. Optional second opinion. | `Run the quality gate.` |

### Rules for using them

1. **Load the matching skills before planning** (table above) — don't rely on memory of them.
2. **Every build session ends with the `oppam-reviewer` agent.** A session is not complete, and
   nothing is committed, until it returns **READY TO COMMIT**.
3. On **NOT READY**: fix the Blockers and Majors in the main session, then run the reviewer again.
   Don't argue a Blocker away — if you believe it's wrong, show the user the evidence and let them
   decide.
4. **Always give the user the completion report** (format in `oppam-review-gate`) — filled from
   commands actually run, never "should pass".
5. Changes to **login / OTP / 2FA / sessions / impersonation or payments / webhooks / refunds**
   additionally require the user's own review before commit, even with READY TO COMMIT.
6. The reviewer needs the git repo and XAMPP's MySQL running; if it can't run a command it must
   say so, and the task stays incomplete.

## Start of every session

1. Read `docs/progress.md` — find the current phase and the next unchecked item.
2. Read only the PRD sections that item names (don't load the whole PRD; it is ~2,700 lines).
3. Load the skills that apply: always `oppam-code-standards` + `oppam-testing`; add
   `oppam-ui-standards` when views/CSS/JS change; `oppam-review-gate` before finishing.
4. **Plan before coding** for anything bigger than a one-file fix. List migrations, models,
   actions, Livewire components, views, tests. Wait for approval.
5. Build → write tests (success + security matrix) → run tests → fix → pint + phpstan →
   self-check with `oppam-review-gate` → run the `oppam-reviewer` agent → give the **completion
   report** → update `docs/progress.md` → commit (only when asked).

If the PRD and a request disagree, **stop and ask** — never silently pick one. If the PRD is
silent, propose an answer and ask; record the decision in `docs/decisions.md`.

## Stack

| | |
|---|---|
| Backend | PHP 8.2+ (**Laravel 12**) · local: XAMPP PHP 8.2 + MariaDB 10.4 · production: PHP 8.3, **MySQL 8**, Redis 7 |
| UI | Blade + **Livewire 4** + Alpine.js, Bootstrap 5.3 + the template's `style.css` / `responsive.css`, Swiper 11, Vite |
| Real-time | **Laravel Reverb** (Pusher protocol) + Laravel Echo + pusher-js. `BROADCAST_CONNECTION=reverb` (or `pusher` — no code change) |
| Queues | Production: Redis + **Laravel Horizon** (supervisors realtime / messaging / general / heavy). Local: `database` driver + `queue:listen`. Queues: `default`, `broadcasts`, `notifications`, `mail`, `media`, `matching`, `imports` |
| Packages | spatie/laravel-permission, spatie/laravel-medialibrary, laravel/fortify (admin 2FA), maatwebsite/laravel-excel, barryvdh/laravel-dompdf, razorpay/razorpay |
| Tests | **Pest** (+ `Livewire::test()`), Laravel Dusk for real-time E2E |
| Dev env | **Windows + XAMPP** (no Docker, no Redis). Horizon can't run locally (no `pcntl`); CI runs on Linux with MySQL 8 + Redis for production parity. See `docs/decisions.md` |

## Commands (Windows + XAMPP — run from the project root)

```bash
# XAMPP: start MySQL (MariaDB) from the XAMPP control panel first
composer dev                       # web (http://localhost:8000) + queue worker + Reverb + Vite, all together
                                   # admin panel: http://admin.localhost:8000  (use "localhost", not 127.0.0.1)
php artisan migrate:fresh --seed   # LOCAL ONLY
composer check                     # = lint + analyse + test  (what CI and the reviewer run)
composer lint                      # Pint --test          (php vendor/bin/pint   to fix)
composer analyse                   # Larastan level 6
composer test                      # Pest in parallel     (php vendor/bin/pest --filter=Likes  for one area)
php artisan dusk                   # browser tests (needs `composer dev` running for Reverb)
php artisan schedule:work          # scheduler, when a session needs it
```

Windows gotchas:
- In **PowerShell**, `composer` runs through `composer.bat`, which silently strips `^` from
  version constraints (`^4.0` becomes `4.0`). Run composer with version constraints from **Git Bash**,
  or use `~`.
- `composer.json` pins `config.platform.php = 8.2.12` and fakes `ext-pcntl`/`ext-posix` so Horizon
  installs; don't remove these.
- Tests use the `oppam_testing` database (parallel runs create `oppam_testing_test_N`).

## Surfaces & routing

| Surface | Host / prefix | Routes file | Guard | Layout |
|---|---|---|---|---|
| Public + member site | `oppam.in` (`config('oppam.app_domain')`) | `routes/web.php` | `web` | `layouts.public`, `layouts.member` |
| Broker portal | `oppam.in/broker` (same host) | `routes/broker.php` | `web` + `role:BROKER` + `broker.active` + `broker.staff.active` | `layouts.broker` |
| Admin panel | `admin.oppam.in` (`config('oppam.admin_domain')`) | `routes/admin.php` | `admin` + `2fa.confirmed` + `ip.allowlist` | `layouts.admin` |
| Channels | — | `routes/channels.php` | per channel | — |
| Webhooks | `/webhooks/*` | `routes/webhooks.php` | signature-verified, no CSRF | — |

## Code layout — four layers, keep them separate

```
app/Livewire/{Public,Member,Broker,Admin,Shared}/   Presentation: state + calls Actions. NO business logic, NO queries beyond simple reads.
app/Actions/{Domain}/                              Application: one use-case per class, handle(): business rules,
                                                    authorization, DB::transaction, events. Called by Livewire, controllers, jobs.
app/Domain/{Domain}/, app/Models, app/Enums,        Domain: pure rules (MatchScorer, BureauGate, visibility), models, enums,
app/Policies, app/Events, app/ValueObjects          policies, events, value objects (Money, PhoneNumber, HeightCm).
app/Data/{Domain}/                                  Readonly DTOs crossing layers (no associative arrays).
app/Contracts/ + app/Services/{Area}/               Infrastructure interfaces + implementations: SmsGateway, PaymentGateway,
                                                    BiodataPdfRenderer, ContentSafety, ImportTemplateBuilder.
app/Queries/{Domain}/                               Complex read queries (ProfileSearchQuery).
app/Notifications/{Domain}/                         One class per notification type (database + broadcast + mail/sms per prefs).
resources/views/components/{ui,profile,...}/        Blade UI kit + components from template partials.
```
Full tree, naming and growth rules: `oppam-code-standards` → `references/project-structure.md`.

Plain controllers only for: file downloads/streams (PDF, zip, xlsx, invoices), webhooks, and
`/broadcasting/auth`. Everything else is a Livewire component.

## Non-negotiable conventions

- **IDs:** ULID primary keys (`$t->ulid('id')->primary()`, `HasUlids`). Public codes are separate
  columns (`profiles.code = OPM12370`, `brokers.code = BRK1042`). **URLs use codes, never ULIDs.**
- **Money:** integers in **paise** (`price_paise`). Never floats. Amounts are always computed
  server-side from a plan key — never accepted from the browser.
- **Enums:** PHP backed enums in `app/Enums`, stored as strings.
- **Dates:** stored UTC, displayed IST (`Asia/Kolkata`).
- **Master data** (religion, caste, star, district…) comes from `master_*` tables via a cached
  `Masters` service — never hardcoded arrays. Caste is always filtered by religion.
- **Strings** through `__()` (Malayalam comes later).
- **Soft deletes** on member-owned data; **audit_logs are append-only** (never update/delete).

## Security rules (release-blocking)

1. **Authorize in the Action / component action, not in the view.** `@can` only hides buttons.
   Every Livewire action that changes data calls `$this->authorize(...)` or a Policy.
2. **`#[Locked]`** on every Livewire public property that holds an id, code, or anything the user
   must not change.
3. **Scope queries by the authenticated owner** (`where profile_id = auth profile`,
   `where managed_by_broker_id = caller's broker`) — never trust an id from input. Foreign
   records → **404** (exports → 403 + log).
4. **Blocked pairs** never see each other (404), never receive each other's events or notifications.
5. **Entitlements** (likes/day, interests/month, contact views, chat send) are enforced in Actions
   via `EntitlementService`, never only in the UI.
6. **Broadcast payloads** carry codes and minimal display data only — no ULIDs, phone, email, or
   private photos. Sensitive data is fetched through a normal authorized request.
7. **ID documents, PAN, horoscopes, import files** → private disk, encrypted, served only via
   short-lived signed URLs with an audit row.
8. **Every admin write** goes through an Action that calls `AuditLogger::record()`.
9. Escape output (`{{ }}`); `{!! !!}` only for sanitized rich text from the CMS.
10. Rate-limit auth, OTP, likes, interests, messages, search (`RateLimiter` in Actions).
11. Never commit `.env`, keys, or real member data. Seeders use fake data only.

## Livewire conventions

- Full-page components for pages (`Route::get('/search', Search::class)`), nested components for
  widgets (`<livewire:shared.like-button :profile-code="$p->code" :key="..."/>`).
- Navigation uses **`wire:navigate`** everywhere in member/broker areas (keeps the WebSocket alive).
- Search/filter state uses `#[Url]` properties; debounce text inputs (`.live.debounce.400ms`).
- Use Form Objects (`app/Livewire/Forms`) for multi-field forms; the wizard uses one per step.
- Heavy sections use `#[Lazy]` with a skeleton placeholder.
- Optimistic UI (likes, chat bubbles) lives in Alpine; the server confirms.
- Real-time listeners: `#[On('echo-private:chat.{conversation.id},.message.sent')]` or
  `getListeners()` — event names use a leading dot with `broadcastAs()`.

## Real-time conventions (PRD §9)

- Persist first, broadcast after commit (`ShouldDispatchAfterCommit` / `afterCommit()`).
- Chat events `ShouldBroadcastNow`; everything else `ShouldBroadcast` on the `broadcasts` queue.
- Use `->toOthers()` when the sender's tab already rendered the change.
- Channels (see PRD §9.3 and §11A B.15): `App.Models.User.{id}`, `chat.{conversation}`,
  `inbox.{profile}`, `presence.online`, `presence-chat.{conversation}`, `broker.{broker}`,
  `broker-owner.{broker}`, `admin.queues`, `admin.dashboard`. Every channel callback re-checks the
  DB (participant, not blocked, active staff…).
- Reconnect must back-fill from the DB (`loadSince($lastId)`); polling fallback behind the
  `realtime.polling_fallback` flag.

## Template conversion rules

- The template's look is the spec: keep its HTML structure, CSS classes, design tokens
  (`--color-primary #e02349`, `--color-secondary-dark`, spacing/radius/shadow tokens) and the
  **3/6/3 dashboard layout**. Don't restyle; don't add Tailwind.
- Template partials → Blade components; `auth.php`/`is_logged_in()` → `@auth`; `csrf_field()` →
  `@csrf`; `ee()` → `{{ }}`; demo arrays (`profiles-data.php`, `plans.php`, `stories-data.php`) →
  Eloquent.
- Keep the template's accessibility rules: one `<h1>` per page, `<main id="main" tabindex="-1">`,
  skip link, every control has `name`/`id`/`<label>`, no `<a>` inside `<a>`, buttons (not
  `href="#"`) for actions, contrast tokens for text.
- Known template fixes: remove "VishwakarmaMatrimony" text; login/register become
  `<button type="submit">`; bell counts become live; caste depends on religion.
- Font Awesome 4.7 → 6 is a separate task (class renames) — don't mix it into feature work.

## Testing rules

- Every Action has feature tests for: happy path, each business rule (quote its PRD id, e.g.
  `R-M06-3`), authorization failure, and validation failure.
- Every Livewire component: renders, authorizes, and rejects tampered `#[Locked]` props.
- IDOR tests for anything scoped to an owner (profile, conversation, broker).
- Broadcasting: assert channel + payload with `Event::fake()`; channel-auth tests in
  `tests/Feature/Broadcasting`.
- Don't weaken or delete a failing test to make it pass — fix the cause or ask.
- Factories for every model; seeders: masters, plans, 200 demo profiles, 3 brokers with staff,
  one admin per role (`admin@oppam.test` / password in `.env.example` only).

## Domain quick reference (details in PRD)

- Profile status: `DRAFT → PENDING_REVIEW → ACTIVE | REJECTED`; also `HIDDEN`, `SUSPENDED`, `DELETED`.
  Only `ACTIVE` profiles are searchable.
- Engagement: **Like** (public signal, daily limit) · **Favorite** (private bookmark, never
  notified) · **Interest** (`PENDING → ACCEPTED | DECLINED | WITHDRAWN | EXPIRED`, 30-day expiry,
  90-day re-send cooldown). Accepting an interest creates the conversation.
- Chat only between accepted pairs; Free members read + 1 reply per conversation; paid members send.
- Plans: Silver ₹499, Gold ₹999 (featured), Diamond ₹1,999/month; Free = no subscription row.
  Limits in `plan_features` (PRD §7.3).
- Broker module: **everything in PRD §11A** (roles OWNER/MANAGER/DATA_ENTRY/TELECALLER, managed
  profiles, claim flow, exports, bulk import, commission on pre-GST subtotal of first paid order).
- Admin RBAC permission keys: PRD §8.4 and each admin module; admins always need 2FA.

## Don'ts

- Don't add a JS framework (React/Vue/Inertia) or a separate API for the web UI.
- Don't put business logic in Blade views or Livewire render methods.
- Don't query inside loops in views (eager load; Larastan + `Model::preventLazyLoading()` in dev).
- Don't hardcode prices, limits or thresholds — they live in `plan_features` / `settings` (A15).
- Don't enable a feature in production before its tests pass and its flag is set.
- Don't run destructive commands (`migrate:fresh`, `db:wipe`) against anything but local.
- Don't commit unless asked; never force-push.
