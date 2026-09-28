# Clean code — PHP rules for Oppam

## File basics

- Every PHP file starts with `declare(strict_types=1);`.
- Classes are `final` by default (Actions, Services, DTOs, Livewire components, Jobs, Events).
  Remove `final` only when a real subclass exists.
- Full type declarations on every property, parameter and return (`void`, `never`, `?Type`,
  union types when unavoidable). No `mixed` unless it truly is.
- Constructor property promotion + `readonly` for injected dependencies and DTO fields.
- One class per file; file name = class name.
- Imports (`use`) — no fully-qualified names inline; no unused imports (Pint removes them).

## Naming

- Names say **what, in domain language**: `$acceptedInterests`, `$managedProfile`,
  `$remainingContactViews` — not `$data`, `$arr`, `$temp`, `$flag`, `$res`.
- Booleans read as questions: `$isBlocked`, `$hasActivePlan`, `$canMessage()`.
- Methods are verbs: `approve()`, `calculateScore()`, `resolveVisibility()`.
- Avoid abbreviations except universal ones (`id`, `url`, `pdf`, `otp`, `kyc`).
- Don't encode types in names (`$profileArray`, `$strName`).

## Functions and methods

- Do one thing; ≤ ~25 lines; ≤ 3–4 parameters (more → a DTO).
- **Early returns / guard clauses** instead of nested `if`s.
- No boolean "mode" parameters (`send($x, true)`) — make two methods or use an enum.
- Pure where possible: domain classes (`MatchScorer`, `AgeRule`) take inputs, return outputs, no
  DB or facades — easy to unit test.

```php
// ✗ nested, stringly-typed, mixed concerns
public function send($from, $to, $msg = null) {
    if ($from->status == 'ACTIVE') {
        if (!Block::where(...)->exists()) {
            if ($from->interests_left > 0) { /* … */ }
        }
    }
}

// ✓ guard clauses, enums, one responsibility, rules delegated
public function handle(Profile $from, Profile $to, ?string $note = null): Interest
{
    $this->gate->authorize('sendInterest', [$from, $to]);          // policy: active, not blocked, contact filter
    $this->entitlements->consume($from, Entitlement::InterestsPerMonth);  // throws QuotaExceeded

    return DB::transaction(fn () => $this->createInterest($from, $to, $note));
}
```

## Classes

- **Single responsibility**: an Action does one use case; a Service wraps one external system; a
  Livewire component manages one screen concern.
- **Dependency injection** through the constructor; resolve with `app(Action::class)` or method
  injection in Livewire. Don't `new` services inside business code.
- Facades are fine in presentation and infrastructure. In `app/Domain` prefer injected contracts
  (so the logic is unit-testable).
- No static state, no singletons holding request data.
- Composition over inheritance; traits only for genuinely shared model behaviour
  (`HasProfileCode`, `BelongsToBureau`), never to share business rules.

## Data between layers

- Use **readonly DTOs** (`app/Data`) instead of arrays when data crosses a boundary
  (component → action, action → event, job payloads).
- Use **enums** for every status/type/role; add `label()` and, if useful, `color()` for UI.
- Use **value objects** for values with rules: `Money::paise(49900)->formatted()`,
  `PhoneNumber::fromInput('98470 12345')` (normalises to E.164, throws on invalid),
  `HeightCm::fromInput("5'4\"")`.
- Never pass Eloquent models into broadcast payloads or job payloads that leave the app —
  use DTOs / codes.

## No magic

- No magic numbers or strings: limits and thresholds come from `Settings`/`EntitlementService`;
  fixed domain constants live on the enum or class as named constants with a comment.
- No `env()` outside `config/`. Code reads `config('oppam.x')`.
- No hidden side-effects in accessors/mutators (don't send mail from a model setter).

## Errors

- Throw **domain exceptions** with a stable code (`QuotaExceeded::interests()`,
  `BrokerKycRequired`, `ProfileNotManagedByBroker`) from Actions; the presentation layer turns them
  into user messages (Livewire: `$this->addError()` / toast; controllers: HTTP status).
- Never catch `\Throwable` just to hide it. Catch specific exceptions, handle, or rethrow.
- Transactions wrap multi-write use cases (`DB::transaction`); broadcast/notify **after commit**.
- External calls (SMS, Razorpay, S3) have timeouts and retries in the Service/Job, not in Actions.

## Logging

- Use structured context: `Log::warning('export.denied', ['broker' => $broker->code, 'profile' => $profile->code])`.
- **Never log**: passwords, OTPs, tokens, message bodies, ID document numbers, phone numbers,
  emails, bank/PAN data, full request payloads. Log codes and ids.
- Security-relevant events (denied export, failed 2FA, impersonation) go to `audit_logs`, not only
  to the log file.

## Comments & docs

- Code explains *what*; comments explain *why* (a business reason, a PRD rule id, a non-obvious
  constraint): `// R-M13-29: an existing mobile can never be imported — protects the claim flow.`
- No commented-out code, no TODO without an owner/ticket (`// TODO(P7.7): …`).
- PHPDoc only where types can't express it (generics: `@return Collection<int, Profile>`).

## Eloquent

- Always eager-load what a view uses (`with()`), and `withCount()` for counters.
- `$fillable` explicitly listed (never `$guarded = []`); sensitive fields (status, role,
  `managed_by_broker_id`, `is_verified`, prices) are **not** fillable — set them in Actions.
- Casts for enums, dates, booleans, `encrypted` for PAN/bank/document numbers.
- Scopes for reusable filters (`scopeActive`, `scopeVisibleTo(Profile $viewer)`).
- Chunk/lazy for large sets (`lazyById()`), never `->get()` on unbounded tables.
- No queries in Blade views or in `render()` loops.

## Formatting

Pint with the project `pint.json` (Laravel preset + `declare_strict_types`, `final_class`,
`ordered_imports`, `no_unused_imports`). Don't hand-format against it.
