---
name: oppam-code-standards
description: Oppam Matrimony's professional code standard — large-project structure, clean-code rules, Laravel 12 patterns (Actions, DTOs, Policies, Services, Events, Jobs, Notifications), database/migration standards, Livewire 4 and Reverb real-time rules. Load BEFORE writing or changing any PHP, Blade, Livewire, migration, route, or JS in this project, and when deciding where new code belongs. Oppam project only.
---

# Oppam code standards

This is the house standard for the Oppam Matrimony Laravel application. It exists so a large
codebase (member site + broker portal + admin panel + real-time) stays easy to read, change and
hand over — whether a human or an AI agent wrote the code. `CLAUDE.md` is the short version; this
skill is the deep version. Where they disagree, `CLAUDE.md` wins and this skill must be fixed.

Companion skills: `oppam-testing` (how to prove it works), `oppam-ui-standards` (how screens must
look and behave), `oppam-review-gate` (what must be checked and reported before anything is "done").

## The ten rules that override convenience

1. **Inspect before you write.** Read the existing code, routes, tests and the PRD section for the
   feature. Search for an existing Action/Service/component before creating one.
2. **One place per rule.** A business rule lives in exactly one Action or domain class. Views,
   Livewire components and controllers call it; they never re-implement it.
3. **The server decides.** Roles, prices, ownership, permissions, limits and statuses are never
   taken from the browser.
4. **Authorize every action, scope every query.** Policy/Gate check + owner-scoped query, always.
5. **Small, named things.** Small classes, short methods, names from the domain (Interest, Like,
   ManagedProfile, Bureau) — not `Helper`, `Manager`, `Util`, `Data2`.
6. **Explicit over clever.** Typed properties, return types, enums, DTOs. No magic strings for
   statuses, no arrays-of-arrays passed between layers.
7. **Fail loudly and safely.** Domain exceptions with codes; never swallow errors; never log PII.
8. **Stay in scope.** Change only what the task needs. No drive-by refactors, no new packages
   without a reason recorded in `docs/decisions.md`.
9. **Tests are part of the change,** not a follow-up (see `oppam-testing`).
10. **"It works on my screen" is not done** — the review gate decides (see `oppam-review-gate`).

## Where does this code go?

| You are writing… | It goes in | Never in |
|---|---|---|
| A use case ("send interest", "import batch rows") | `app/Actions/{Domain}/VerbNoun.php` | Livewire component, controller, model, Blade |
| A rule reused by many actions ("is this pair blocked?", "can view photo?") | Domain service / policy / model method: `app/Domain/{Domain}/…` or `app/Policies` | Copy-pasted `if`s |
| Talking to an outside system (SMS, Razorpay, S3, PDF) | `app/Services/{Area}/` behind an interface in `app/Contracts` | Actions calling SDKs directly |
| Screen state + user interaction | `app/Livewire/{Surface}/{Area}/Name.php` + view | Business rules, raw SQL, `DB::` calls |
| A file download / webhook / broadcasting auth | Thin controller in `app/Http/Controllers/{Surface}` | Livewire |
| Something that happens later or slowly | Job in `app/Jobs/{Domain}` on the right queue | Inline in the request |
| "Tell the user" | `app/Notifications/{Domain}` | Ad-hoc `Mail::` calls |
| "Tell the browser live" | `app/Events/{Domain}` implementing `ShouldBroadcast(Now)` | Manual Pusher calls |
| A reusable query filter | Eloquent scope or `app/Queries/{Domain}` query object | Repeated `where` chains in components |
| A value with rules (Money, PhoneNumber, HeightCm, ProfileCode) | `app/ValueObjects` (immutable, `readonly`) | Raw ints/strings with rules sprinkled around |
| Data crossing a layer boundary | `app/Data/{Domain}/…Data.php` readonly DTO | Associative arrays |
| Fixed set of values | `app/Enums` (backed enum, with `label()`) | String literals |
| Tunable number (limits, days, thresholds) | `settings` / `plan_features` via `Settings`/`EntitlementService` | Constants in code |

## Read the right reference

- **`references/project-structure.md`** — the full directory layout for this large project,
  namespaces, file/route/view naming, where each surface's code lives. Read when creating any
  new file or folder.
- **`references/clean-code.md`** — PHP style and clean-code rules with good/bad examples. Read
  before writing PHP.
- **`references/laravel-patterns.md`** — templates for Action, DTO, Policy, Service contract,
  Event, Job, Notification, migration, and database standards. Read when creating any of those.
- **`references/livewire-realtime.md`** — Livewire component rules and the Reverb/Echo real-time
  contract. Read before touching a Livewire component or anything live.

## Toolchain that enforces this

```bash
php vendor/bin/pint     # formatting (Laravel preset + strict types, final classes — pint.json)
composer lint           # Pint --test (what CI runs)
composer analyse        # Larastan level 6 minimum, no new baseline entries
composer test           # all tests, parallel
composer check          # all three
```

A change that needs a new PHPStan baseline entry, a `@phpstan-ignore`, or a skipped test must say
why in the completion report — and it is a reviewer decision, not an agent decision.
