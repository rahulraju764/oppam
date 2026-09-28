---
name: oppam-testing
description: Oppam Matrimony testing standard — the testing pyramid (unit, feature, Livewire, browser/Dusk), the mandatory security test matrix for every protected action, real-time/broadcast tests, factories and naming. Load when writing or reviewing tests, when finishing any feature, or when asked to prove something works. Oppam project only.
---

# Oppam testing standard

A feature is not done until tests prove it — including the ways it must **refuse** to work. Use
Pest 3 (the Pest Livewire plugin doesn't support Livewire 4 yet — use `Livewire::test()`
directly). Test names describe behaviour and quote the PRD rule id when one exists.

```bash
composer test                                  # everything, parallel
php vendor/bin/pest --filter=Interest          # one area
php vendor/bin/pest --coverage --min=80        # coverage gate for app/Actions + app/Domain (needs Xdebug/PCOV)
php artisan dusk                               # browser journeys (`composer dev` running for Reverb)
```

## Testing pyramid

### 1. Unit tests — `tests/Unit/{Domain}` (no DB, no HTTP, fast)

Use for isolated logic:
- Age calculation (18 F / 21 M, birthdays on leap days, IST midnight boundary)
- Match scoring (`MatchScorer` weights 60/25/10/5, missing preferences, caps)
- Preference rules (partner-preference fit, "you match X of Y")
- Visibility rules (photo / phone / horoscope visibility × viewer plan × connection state)
- Status transitions (interest state machine, profile status, import batch status, payout run)
- Custom validation rules (`MinimumMarriageAge`, `CasteBelongsToReligion`, `IndianMobile`,
  height/date parsing used by bulk import)
- Value objects (`Money`, `PhoneNumber`, `HeightCm`), commission maths (pre-GST, bps rounding)

Use datasets for rule tables:
```php
it('decides photo visibility', function (PhotoVisibility $setting, string $viewer, bool $sees) {
    expect(PhotoVisibilityResolver::canSee($setting, viewerState($viewer)))->toBe($sees);
})->with([
    [PhotoVisibility::AllMembers,   'free',      true],
    [PhotoVisibility::PremiumOnly,  'free',      false],
    [PhotoVisibility::PremiumOnly,  'gold',      true],
    [PhotoVisibility::AcceptedOnly, 'gold',      false],
    [PhotoVisibility::AcceptedOnly, 'connected', true],
]);
```

### 2. Feature tests — `tests/Feature/{Domain}` (DB, Actions, HTTP, jobs)

Use for complete Laravel behaviour:
- Authentication (register, OTP limits, login, non-enumeration, suspended users, admin 2FA)
- Database workflows (Actions end-to-end with transactions; rollbacks on failure)
- Policies (every ability, allow and deny)
- Search (filters, exclusions: blocked, ignored, incognito, non-ACTIVE; sort; pagination)
- Interests (every state transition, quota, cooldown, contact filter, expiry job)
- Blocks and reports (symmetric hiding, side-effects on likes/interests/conversations)
- Notifications (right recipients, channels per preferences, quiet hours, aggregation, never for
  blocked actors)
- Admin actions (permission required, audit row written, typed-reason confirmations)
- Payment webhooks (signature, idempotency, amount from plan key, activation, refund side-effects)
- Jobs (idempotent re-run, chunking, failure behaviour)
- Broker (IDOR scoping, bureau permission matrix, exports, claim, import pipeline, payouts)

### 3. Livewire tests — `tests/Livewire/{Surface}/{Area}`

For every component check:
- **Rendering** (with realistic seeded data; `assertSee` key text; no N+1 — run with
  `Model::preventLazyLoading()`)
- **Validation errors** (`->set('form.dob', …)->call('next')->assertHasErrors(['form.dob'])`)
- **Actions** (state after the call, DB side-effects)
- **Events** (`assertDispatched('toast')`, broadcast events faked)
- **Authorization failures** (wrong user, wrong role → `assertForbidden()` / `assertNotFound()`)
- **Tampered locked props** (`->set('profileCode', 'OTHER')` throws `CannotUpdateLockedPropertyException`)
- **Loading states** (`wire:loading` markup present on the action buttons — `assertSeeHtml`)
- **Empty states** (no data → the designed empty state text)
- **Pagination** (page 2 / load more returns the next slice, stable order)
- **Notification count changes** (bell count before/after an event; mark-all-read)

### 4. Browser tests — `tests/Browser` (Laravel Dusk, real Reverb)

Smoke-test the critical journey end-to-end with two browsers:

```text
register → profile → photo → search → interest → accept → chat
→ notification → block/report
```

Plus: bureau onboarding (invite staff → bulk import → moderation approval arrives live), payment
(test gateway → chat unlocks live), reconnect back-fill, mobile width (375 px) for the journey.

**Do not treat a successful HTTP 200 response as proof that a browser workflow works.** A page can
return 200 with a broken Livewire action, a JS error, a missing WebSocket subscription, or a
button hidden off-screen on mobile. Workflows are proven by Dusk clicking through them.

## Security test matrix (mandatory for every protected action)

For each Action / Livewire action / controller endpoint that reads or changes protected data, add a
dataset covering:

| Case | Expected |
|---|---|
| Guest user | redirect to login / 401 |
| Authenticated **wrong user** (another member, another bureau) | 404 (403 only for exports) |
| **Suspended** user | blocked, session revoked |
| **Blocked** user (either direction) | 404 / refused, no notification |
| **Unverified** user (phone not verified, profile not ACTIVE, broker KYC pending) | refused with the right code |
| **Missing** record | 404 |
| **Tampered id** (foreign code/ULID, locked prop change) | 404 / exception, nothing written |
| **Invalid input** | validation errors, nothing written |
| **Rate limit** exceeded | 429 / friendly message, nothing written |
| Wrong role (bureau Telecaller, admin without permission) | 403 |

Helper: `tests/Support/SecurityMatrix.php` provides `actors()` datasets (`guest`, `owner`,
`otherMember`, `suspended`, `blockedPair`, `unverified`, `telecaller`, `adminWithout('perm')`).

Also check explicitly for: **IDOR, mass assignment** (post extra fields like `status`,
`is_verified`, `managed_by_broker_id`, `price_paise` → ignored), **XSS** (store `<script>` in
about/notes/messages/import cells → escaped on render), **CSRF** (Livewire handles it; webhook
routes excluded only with signature checks), **SQL injection** (search/sort params with quotes and
unknown columns → rejected by allow-lists), **unsafe uploads** (PHP/SVG/HTML renamed to .jpg,
zip-slip paths, oversized files), **private-file exposure** (ID docs, PAN, horoscopes, import files
unreachable without a signed URL), **privilege escalation** (member → broker routes, staff → owner
pages, admin role editing), **notification-channel leakage** (subscribing to another user's
`App.Models.User.{id}`, `chat.{id}`, `broker.{id}` is denied; payloads contain no private fields).

## Real-time tests — `tests/Broadcasting`

- Channel auth: allowed and denied cases for every channel in PRD §9.3 / §11A B.15.
- Event payloads: `Event::fake()` + assert channel names and that `broadcastWith()` has no ULIDs,
  phone, email, message bodies for non-participants, private photo URLs.
- `broadcastWhen()` suppresses events for blocked pairs.

## Factories & data

- Factories for every model with meaningful states: `Profile::factory()->active()->female()->gold()`,
  `->brokerManaged($broker)`, `->incomplete()`; `Broker::factory()->kycVerified()->withStaff(role)`.
- Test helpers: `actingAsMember()`, `actingAsBureau(BureauRole::Telecaller)`, `actingAsAdmin('brokers.payout')`.
- Fake external systems: `SmsGateway` → log fake, `PaymentGateway` → fake with signed webhook
  builder, `Storage::fake('private')`, `Notification::fake()`, `Event::fake()` selectively.
- Freeze time for time rules (`$this->travelTo(...)`), IST boundaries included.

## Rules

- Every PRD business rule used by the change has at least one test named with its id.
- Test success **and** failure paths; the failure paths are the security matrix.
- Never delete, skip or weaken a failing test to go green — fix the cause or report it.
- No sleeping in tests (`sleep()`); Dusk uses `waitFor…`.
- Tests are independent (RefreshDatabase / LazilyRefreshDatabase), no order dependence.
- Coverage gate: ≥ 80 % lines for `app/Actions` and `app/Domain`; release-blocking (★) tests listed
  in PRD §11A B.22 and the IDOR/export/permission-matrix tests must always pass.
