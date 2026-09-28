# Laravel patterns & database standards

Copy these shapes. Consistency across ~150 Actions is what keeps the project maintainable.

## Action

```php
<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Data\Engagement\InterestData;
use App\Domain\Engagement\InterestRules;
use App\Enums\Entitlement;
use App\Events\Engagement\InterestSent;
use App\Models\Interest;
use App\Models\Profile;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SendInterest
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly InterestRules $rules,
    ) {}

    public function handle(Profile $from, Profile $to, ?string $note = null): Interest
    {
        Gate::forUser($from->user)->authorize('sendInterest', $to);    // active, not blocked, contact filter
        $this->rules->assertNoCooldown($from, $to);                    // R-M06: 90-day re-send cooldown

        return DB::transaction(function () use ($from, $to, $note): Interest {
            $this->entitlements->consume($from, Entitlement::InterestsPerMonth);   // throws QuotaExceeded

            $interest = Interest::create([
                'from_profile_id' => $from->id,
                'to_profile_id'   => $to->id,
                'message'         => $note,
                'expires_at'      => now()->addDays(settings('interest.expiry_days')),
            ]);

            InterestSent::dispatch(InterestData::fromModel($interest));      // listeners run after commit

            return $interest;
        });
    }
}
```

Rules: `final`, constructor-injected deps, one public `handle()`, authorize first, validate business
rules, transaction for multiple writes, events/notifications after commit, return a model or DTO,
throw domain exceptions. Input validation of *shape* (required, max length) happens in the Livewire
Form / Form Request; the Action validates *business* rules. When an Action can be called from a
place without prior validation (jobs, imports), it validates shape too via a shared `…Rules` class.

## DTO

```php
final readonly class ProfileSearchCriteria
{
    public function __construct(
        public Gender $gender,
        public int $ageMin,
        public int $ageMax,
        /** @var list<int> */ public array $religionIds = [],
        public ?int $districtId = null,
        public bool $verifiedOnly = false,
        public SearchSort $sort = SearchSort::Relevance,
    ) {}

    public static function fromComponent(Search $c): self { /* map + clamp */ }
}
```

## Policy

- One policy per model; method names match the ability: `view`, `update`, `sendInterest`,
  `message`, `export`.
- Policies answer **"may this actor do this to this record"** — they may call domain services
  (block checker, bureau gate) but never write.
- Missing/foreign records: controllers & components `abort(404)` on scoped lookup **before** the
  policy runs (don't leak existence with 403), except exports (403 + log per PRD).

## Services & contracts

```php
interface SmsGateway { public function send(PhoneNumber $to, SmsTemplate $template, array $vars): SmsResult; }
final class Msg91Gateway implements SmsGateway { /* HTTP client with timeout(5)->retry(2, 200) */ }
final class LogSmsGateway implements SmsGateway { /* local/testing */ }
// AppServiceProvider: $this->app->bind(SmsGateway::class, fn () => config('oppam.sms.driver') === 'msg91' ? … : …);
```

Every external system has a contract + a fake/log implementation used in tests and local dev.

## Events, listeners, broadcasting

- Domain events are past-tense DTO carriers. Broadcast events implement `ShouldBroadcast` (queued
  on `broadcasts`) or `ShouldBroadcastNow` (chat), plus `ShouldDispatchAfterCommit`.
- `broadcastAs()` returns a dotted name (`message.sent`); `broadcastWith()` returns only codes +
  display data (never ULIDs, phone, email, private photo URLs).
- `broadcastWhen()` guards blocked pairs / disabled features.
- Cross-domain reactions go through listeners (`OrderPaid` → `AttributeCommission`), never direct
  calls from Billing into Broker.

## Jobs

- Named imperatively, `final`, `ShouldQueue`, explicit `$queue`, `$tries`, `$backoff`, `$timeout`.
- Idempotent (safe to run twice): check state before acting, unique keys, `ShouldBeUnique` where
  duplicates would hurt (payout runs, daily match generation per member).
- Payload = ids/codes, not whole models with loaded relations.
- Large sets processed with `lazyById()` / chunked batches (`Bus::batch`) with progress events.

## Notifications

- One class per type in `app/Notifications/{Domain}`; `via()` reads `notification_preferences` +
  quiet hours through a `NotificationRouter`; always `database` + `broadcast` for in-app types.
- `toArray()` shape is fixed: `{type, actor_code, actor_name, actor_photo_thumb, title, body, url, icon}`.
- `ShouldQueue` on the `notifications` queue.

## Migrations & database standards

- ULID PKs (`$t->ulid('id')->primary()`), `foreignUlid()->constrained()` with an explicit
  `cascadeOnDelete()` / `restrictOnDelete()` / `nullOnDelete()` decision on every FK.
- **Constraints enforce rules** the app also checks: `unique` pairs (likes, favorites, interests,
  blocks), `unique(['conversation_id','client_id'])`, enum columns via PHP enums + string columns
  with a check constraint where MySQL supports it.
- **Indexes match real queries**: composite indexes ordered by equality filters first, then range,
  then sort (`['status','gender','dob']`). Add an index in the same migration as the query that
  needs it; justify in a comment.
- Money in `unsignedInteger`/`unsignedBigInteger` paise. Never `decimal` for money, never `float`.
- Timestamps UTC; `softDeletes()` on member-owned data.
- Encrypted casts for PAN, bank account, document numbers; sensitive files on the private disk.
- **Expand/contract** for changes on live tables: add nullable column → backfill job → switch code
  → enforce NOT NULL / drop old column in a later release. Never rename/drop in the same deploy as
  the code change.
- Every migration has a working `down()` for local use; production never runs `down()`.
- Seeders are idempotent (`updateOrCreate` on codes) and contain **no real personal data**.
- Never `migrate:fresh`, `db:wipe` or `truncate` outside local/testing.

## Validation

- Shape validation in Livewire Form Objects (`#[Validate]`) or Form Requests; shared rule sets in
  `app/Domain/{Domain}/…Rules` (e.g. `ProfileRules::basic()`) so the wizard, broker form and bulk
  import use the **same** rules.
- Custom rules in `app/Rules` (`MinimumMarriageAge`, `CasteBelongsToReligion`, `IndianMobile`).
- Never rely on HTML attributes (`required`, `max`, `accept`) — the server re-validates everything.
- File uploads: validate MIME by content (`mimes` + `mimetypes`), size, dimensions; store with a
  generated name; never trust the client filename or extension.

## Config & settings

- Secrets only in `.env` → read via `config()`.
- Business-tunable values in `settings` (admin-editable, cached, audited) — read through
  `settings('interest.expiry_days')` with a typed default registered in one place.
- Feature flags via `Feature::active('broker.bulk_import')`; every new user-facing feature ships
  behind a flag until its gate passes.
