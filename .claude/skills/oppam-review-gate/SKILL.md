---
name: oppam-review-gate
description: Oppam Matrimony release gates — the quality, security, performance, UI and AI-agent review checklists, the "never allow" list, and the required module completion report. Load BEFORE declaring any task, session or module done, before committing, when reviewing AI-generated code or a diff, and when asked to "run the gate", "review this" or "is this ready". Oppam project only.
---

# Oppam review gate

Nothing is "done" because it compiles, returns HTTP 200, or looks right once. It is done when it
passes these gates **and** the completion report is filled in with evidence. This skill is used by
the agent that wrote the code (self-review) and by the reviewer (`oppam-reviewer` agent or the
global `quality-gate` agent, which loads this skill automatically for this project).

Companion skills: `oppam-code-standards`, `oppam-testing`, `oppam-ui-standards`.

## Gate procedure (run in order, stop at the first hard failure)

1. **Scope** — `git status`, `git diff --stat`, then the diff. Compare against the session's scope
   in `docs/build-prompts.md` / `docs/progress.md`. Out-of-scope changes → revert or split.
2. **Automated checks** — all must pass, with output captured for the report:
   ```bash
   composer lint                        # Pint --test
   composer analyse                     # Larastan
   composer test                        # Pest, parallel
   php artisan dusk --filter=<area>     # when UI/real-time changed (needs `composer dev` running)
   ```
3. **Gate A — AI-agent review checklist** (below).
4. **Gate B — Security review** (below + `oppam-testing` security matrix).
5. **Gate C — Performance review** (below).
6. **Gate D — UI review** (`oppam-ui-standards`: states, responsive widths, accessibility).
7. **Completion report** — filled in, every claim backed by a command output, test name or file.
8. Update `docs/progress.md`; record any deviation in `docs/decisions.md`.

Severity: **Blocker** (must fix before commit), **Major** (fix in this session unless the owner
accepts it in writing), **Minor** (note in report). Any "Never allow" item is a Blocker.

## Gate A — AI-agent review checklist

Before accepting generated code, verify:

- [ ] The agent **inspected existing code first** (searched for existing Actions/components/rules
      before creating new ones; no duplicates).
- [ ] **Only in-scope files changed**; no drive-by refactors or formatting of unrelated files.
- [ ] **Versions and APIs match the installed dependencies** (check `composer.lock` /
      `package-lock.json` — Laravel 12, Livewire 4, Reverb, Pest 3; no APIs from other major versions).
- [ ] **Migrations have constraints and indexes** (FK behaviour chosen, unique pairs, indexes for
      the new queries, money in paise, ULIDs).
- [ ] **Policies protect every resource action**; queries are owner-scoped; `#[Locked]` on id props.
- [ ] **Validation does not rely on browser-only rules**; shared rule sets reused.
- [ ] **Tests cover both success and failure paths** (security matrix included) and name PRD rule ids.
- [ ] **Private data is not exposed** (views, JSON, broadcast payloads, logs, exports, error pages).
- [ ] **No secrets were added** (no keys/tokens/passwords in code, config, tests, docs, commits).
- [ ] **No unnecessary packages were installed** (any new dependency is justified in `docs/decisions.md`).
- [ ] **No destructive command was run** (`migrate:fresh`, `db:wipe`, `rm -rf`, force-push) outside local/test.
- [ ] **Loading, error, empty and success UI states exist** for every new interaction.
- [ ] **Mobile layout was considered** (320/375 px checked, no horizontal scroll).
- [ ] **Full tests and formatting pass** (outputs in the report, not "should pass").

## Gate B — Security review

For every protected action, confirm tests exist for: guest user · authenticated wrong user ·
suspended user · blocked user · unverified user · missing record · tampered ID · invalid input ·
rate-limit behaviour.

Check the diff for:

| Risk | What to look for |
|---|---|
| **IDOR** | Lookups by id/code without owner scope; `find($id)` on user input; broker/staff scope missing |
| **Mass assignment** | `$guarded = []`, `fill($request->all())`, status/role/price/ownership fields fillable |
| **XSS** | `{!! !!}` on user content, unescaped Alpine `x-html`, notification/toast text from user input |
| **CSRF** | New non-Livewire POST routes without `@csrf`; webhook routes without signature checks |
| **SQL injection** | `DB::raw` / `orderByRaw` / `whereRaw` with user input; sort/filter columns not allow-listed |
| **Unsafe uploads** | MIME by extension only, SVG/HTML accepted, client filename used, zip-slip, no size limit |
| **Private-file exposure** | ID docs, PAN, horoscopes, import files on the public disk or with permanent URLs |
| **Privilege escalation** | Member → broker/admin routes, staff → owner pages, admin granting permissions it lacks |
| **Notification-channel leakage** | Channel auth missing a DB check; payloads with ULIDs, phone, email, bodies, private photos |
| **Auth/session** | Changes to login, OTP, 2FA, session lifetime, impersonation — **require human review** |
| **Payments** | Amount from client, webhook without signature/idempotency — **require human review** |
| **Logging** | Passwords, OTPs, tokens, message bodies, phone/email, document numbers in logs |

## Gate C — Performance review

- [ ] **Paginate all unbounded lists** (search, lists, notifications, chat history, admin tables).
- [ ] **Eager-load known relationships**; `withCount` for counters.
- [ ] **No N+1 queries** (tests run with `preventLazyLoading`; Debugbar/Telescope query count checked on new pages).
- [ ] **Indexes exist for actual filters and joins** introduced by the change (EXPLAIN for search-like queries).
- [ ] **Images optimised** (dimensions, WebP conversions, lazy loading).
- [ ] **Queued**: email, SMS, image processing, PDF generation, imports, external notifications.
- [ ] **No high-frequency polling as a substitute for WebSockets** (`wire:poll` only as the flagged fallback).
- [ ] **Measure before adding caching**; any new cache has an invalidation path.
- [ ] Jobs are chunked and idempotent; long operations report progress.

## Gate D — UI review (summary; details in `oppam-ui-standards`)

- [ ] Uses the component kit and tokens; no inline styles, random colours or duplicated class soup.
- [ ] Loading / empty / error / success / disabled states present.
- [ ] Checked at 320, 375, 768, 1024, 1440 px; no horizontal overflow.
- [ ] Keyboard navigation, visible focus, labels, contrast, one `<h1>`, live regions for live updates.
- [ ] Matches the template's look for converted pages.

## Never allow (automatic Blocker)

- Whole-application generation in one step.
- Unreviewed authentication changes.
- Unreviewed payment changes.
- Production database resets.
- Secrets in source control.
- Browser-controlled roles, prices, ownership, or permissions.
- Business logic in Blade templates.
- Private messages or passwords in logs.
- Unverified claims that a feature is complete.

Also Blocker for this project: a broker able to export or view a profile it doesn't manage; a
blocked pair seeing each other; ID documents reachable without a signed URL; a deleted/weakened
test used to make the suite pass.

## Required completion report

For each module/session the agent must report, with evidence:

```text
Feature:              <session id + name, PRD sections implemented>
Files changed:        <grouped: migrations, models, actions, livewire, views, routes, tests, config>
Database changes:     <tables/columns/indexes/constraints; expand-contract notes; seeders>
Authorization:        <policies/abilities/permission keys; how queries are scoped>
Validation:           <rule sets used/added; server-side only rules; upload rules>
Tests added:          <files + notable test names incl. PRD rule ids and security-matrix cases>
Tests run:            <exact commands + pass/fail counts; pint/phpstan result; dusk result>
Security checks:      <Gate B items checked, findings, fixes>
Performance checks:   <Gate C items checked, query counts, indexes, queues>
Known limitations:    <what is not done / deferred / needs human review (auth, payments)>
Next module:          <next unchecked item in docs/progress.md>
```

Rules for the report: no "should work", no "tests would pass" — only what was actually run and
observed. If something wasn't checked, say so under Known limitations. If tests couldn't run, the
task is **not** complete.
