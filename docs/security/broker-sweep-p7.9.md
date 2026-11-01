# Broker security sweep — P7.9 (PRD §11A B.18, B.22)

Date: 2026-10-30. Scope: every route, Livewire action, channel and export of the broker module
(bureau portal `oppam.in/broker` and A07 `admin.oppam.in/brokers`), attacked as each bureau role, as
another bureau, and through stale or revoked access. Built after P7.8a–c (committed with them in
08080f3); reviewer round 1 found coverage gaps 3–6 below (closed in b874fd9), round 2 gaps 7–9.

**Result: no open gaps.** No attack in the tables below reached data or changed anything it
shouldn't. Nine **coverage** gaps (safe but untested — two found by the sweep, seven by its review)
were closed with tests; no application defect was found.

## How it is kept complete

`tests/Feature/Broker/Security/SurfaceInventoryTest.php` (★) lists all 42 routes named `broker.*` /
`admin.brokers.*` and every public action of all 37 bureau / A07 Livewire components (reflection —
Livewire's own pagination / upload hooks and `#[Computed]` values excluded), each with the test
files that attack it, and checks those files exist. A new route or action without a row fails the
build. Every `updatedX` hook is listed too (only Livewire's bare `updated()` / `updating()` are left
out): `updatedHoroscope` writes; the others only reset a filter or page and are mapped to `[]`.

## Attackers

| Attacker | How it is simulated |
|---|---|
| Guest | no session |
| Member account | a `MEMBER` login (no bureau row) |
| Owner / Manager / Data entry / Telecaller | `bureauLogin(role)` of the victim bureau |
| Assigned-only staff | staff row with scope ASSIGNED, or a Telecaller (always assigned-only) |
| Another bureau's Owner | a second verified bureau, holding the victim's codes |
| Stale session | the login's staff row deactivated after signing in |
| Suspended / deactivated bureau | bureau status changed after signing in |
| KYC revoked / creation paused / imports paused | bureau flags changed after the page opened |
| Permission switched off | the Owner's switch-off applied after the page opened |
| Admin without the permission | an admin role lacking the A07 key |

## What was tested

| Surface | Attackers | Expected | Result | Tests |
|---|---|---|---|---|
| 27 portal routes (typed URLs) | guest | redirect to sign-in | ✔ | RouteAttackTest |
| 27 portal routes | member account | 403 / 404 (role:BROKER) | ✔ | RouteAttackTest |
| 27 portal routes, victim's profile / batch / staff / payout-run codes | another bureau's Owner | 404; exports 403 + a refused `broker_profile_exports` row naming the attacker; own pages never show the victim's data | ✔ | RouteAttackTest, IdorScopingTest ★, ExportAuthorizationTest ★ |
| 27 portal routes | stale session (staff deactivated) | signed out on the next request (session gone) | ✔ | RouteAttackTest, StaffLifecycleTest |
| A portal page's Livewire update (persistent middleware) | staff deactivated / bureau suspended / permission switched off after the page loaded | signed out / 403 | ✔ | RouteAttackTest, BureauPermissionMatrixTest |
| 27 portal routes | suspended / deactivated bureau | no portal route opens | ✔ | RouteAttackTest, BrokerDeactivationTest ★ |
| Every route × every role | Owner / Manager / Data entry / Telecaller | B.2 matrix (403 BUREAU_PERMISSION, KYC_REQUIRED, CREATION_PAUSED) | ✔ | BureauPermissionMatrixTest ★ |
| Public routes `/apply`, `/staff/invite/{token}`, `/validate` | signed-in login, anyone | guest-only; validate returns name + district only | ✔ | RouteAttackTest, BrokerApplicationTest, StaffLifecycleTest |
| Form::save (add a client) | page open, then staff deactivated / bureau suspended / KYC revoked / creation paused / switched off | 403, no profile | ✔ | MidFlowRevocationTest |
| Form::save, autosave, open (edit a client) | page open, then staff deactivated / bureau suspended / `profiles.edit` switched off / taken off this client (assigned-only) | refused, nothing written. KYC revoked: editing still works (R-M13-21 and B.19 name create / import / export only; tested; owner to confirm, decisions 2026-11-01) | ✔ (**coverage gap closed**) | MidFlowRevocationTest |
| Upload::startImport | then staff deactivated / suspended / KYC revoked / creation paused / imports paused | 403 (paused: field error), no batch | ✔ | MidFlowRevocationTest |
| BatchReview::import | then staff deactivated / suspended / KYC revoked / creation paused | refused, batch untouched | ✔ | MidFlowRevocationTest |
| Show::change, ClientInbox::send, Notes::add, Index::assignSelected | then staff deactivated / suspended / switched off | refused, nothing written | ✔ | MidFlowRevocationTest |
| Staff\Index::invite, Staff\Show::deactivate | Owner, then bureau suspended | refused | ✔ | MidFlowRevocationTest |
| `#[Locked]` codes on Show, ClientInbox, Notes, Form, Photos, BatchReview, Staff\Show | tampered to another bureau's code | CannotUpdateLockedPropertyException | ✔ | MidFlowRevocationTest |
| Ids sent by the browser: invitation id, follow-up id, assignee id | another bureau's ids | 404 / field error, nothing changed | ✔ | MidFlowRevocationTest |
| Photo actions (delete / move / caption) | another profile's photo uuid via the bureau's own client | 404, photo untouched (order unchanged); foreign profile code → 404 | ✔ (**coverage gap closed**) | MidFlowRevocationTest |
| Photo writes (savePhoto, deleteHoroscope, horoscope upload) | Telecaller (no `profiles.edit`); assigned-only on an unassigned client; staff deactivated / bureau suspended after the page opened | refused, no media written | ✔ (**coverage gap closed**) | MidFlowRevocationTest |
| Import batches: list, page, errors.xlsx, and map / fix / re-upload / attest / start / cancel / submit | assigned-only login, a colleague's batch (owner decision 2026-10-31) | not listed; 404, nothing changed; own batches and "all clients" logins unaffected | ✔ (**scope tightened at the phase close**) | IdorScopingTest ★ |
| Every managed-profile Action | another bureau's / a member's own / a claimed client, as the Owner | 404, nothing changed | ✔ | IdorScopingTest ★ |
| Every managed-profile Action (submit, status, client phone, note, assign, inbox accept / send) | assigned-only login, a bureau client assigned to someone else | 404, nothing changed | ✔ (**coverage gap closed**) | IdorScopingTest ★ |
| Biodata / photo export | non-managed, claimed, assigned-only, wrong role, KYC revoked, suspended | 403 + logged on every attempt | ✔ | ExportAuthorizationTest ★ |
| Claim vs export race | claim and export of the same profile | no window where both succeed | ✔ | ClaimFlowTest |
| Import validation | single form vs import row | one rule set | ✔ | ImportValidationParityTest ★ |
| `broker.{id}` channel | other bureau, suspended login, invited / deactivated staff, closed bureau, member account, unknown id | refused | ✔ (member: **coverage gap closed**) | BrokerChannelsTest, ChannelAttackTest |
| `broker-owner.{id}` channel | Manager / Data entry / Telecaller, other Owner, suspended / KYC-pending bureau, member, unknown id, guest auth | refused | ✔ | BrokerChannelsTest, ChannelAttackTest |
| Broadcast payloads | — | codes and minimal data only | ✔ | BrokerChannelsTest, PayoutRunTest, BrokerDeactivationTest |
| A07 routes (12) and actions | admin without the key; another bureau's staff / batch / dispute / signal ids; tampered locked codes; embedded targets | 403 / 404 / refused | ✔ | BrokerAdminScreensTest, BrokerOversightScreensTest, BrokerSignalsScreensTest, PayoutRunTest, BrokerOversightTest, FraudSignalsTest, BrokerKycTest, UnverifiedCodesQueueTest |
| Payout races | two approvers; mark paid vs suspension; a reused UTR | row lock "already approved"; refused; refused | ✔ | PayoutRunTest (two simultaneous mark-paid calls with one UTR end at the unique index → refusal: not tested under true concurrency) |

## Gaps found

| # | Gap | Kind | Fix |
|---|---|---|---|
| 1 | Photo actions of a managed client were not attacked with another profile's photo id (the code already refused it: `ownPhoto()` scopes by profile) | test coverage | MidFlowRevocationTest "photo actions…" |
| 2 | No test that a member account can't join `broker.*` / `broker-owner.*` (the channel callbacks already refused it) | test coverage | ChannelAttackTest |
| 3 | Photo writes (savePhoto, deleteHoroscope, horoscope upload) were mapped as attacked but nothing called them | test coverage (review) | MidFlowRevocationTest "photo writes…" ×2 |
| 4 | The inventory dropped every `updated*` hook, hiding the horoscope upload (a write) | guard (review) | SurfaceInventoryTest lists `updatedX` hooks |
| 5 | No managed-profile Action was attacked by an assigned-only login on an unassigned client | test coverage (review) | IdorScopingTest "assigned-only login gets 404…" |
| 6 | Stale session / switched-off permission through a real Livewire update was tested for suspension only; weak redirect / session assertions | test coverage (review) | RouteAttackTest Livewire-update tests; session-gone and Location assertions |
| 7 | Form::open and autosave write a partial save on an existing client but were mapped only to happy-path tests | test coverage (review) | MidFlowRevocationTest "edit a client…" |
| 8 | A new portal GET route could be mapped in the inventory without being attacked | guard (review) | RouteAttackTest "every portal page is attacked" |
| 9 | The guest row only asserted a redirect, not one to sign-in | test coverage (review) | RouteAttackTest guest test asserts the login Location |

The sweep itself needed no application change. One rule was tightened afterwards at the owner's
request: assigned-only logins now see only the import batches they uploaded (`BrokerImportBatch::visibleTo`).

## Not covered here (recorded elsewhere)

- True concurrency (two processes at once) is proven by row locks and sequential tests, not by
  parallel load — P9.4 load test.
- Dialogs at widths other than 375 px; pages at all widths are in BrokerPortalTest "Gate D" (P7.8 close-out).
