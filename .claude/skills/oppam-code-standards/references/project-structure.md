# Project structure — large-project layout

Oppam is one Laravel app with three surfaces (Public/Member, Broker, Admin) and ~20 business
domains. Code is organised **by layer first, then by domain**, with the same domain names used in
every layer, so a feature is easy to find everywhere it lives.

## Domain names (use exactly these in every folder/namespace)

`Auth`, `Profile`, `Onboarding`, `Media`, `Search`, `Matching`, `Engagement` (likes, favorites,
interests), `Chat`, `Notification`, `Verification`, `Safety` (blocks, reports, abuse), `Billing`,
`Broker`, `Content`, `Masters`, `Admin`, `Audit`, `Settings`, `Reports`, `Comms` (campaigns).

Adding a domain is an architecture decision → `docs/decisions.md`.

## Directory tree

```
app/
├─ Actions/                     # one use case per class, grouped by domain
│  ├─ Auth/                     RegisterMember, SendOtp, VerifyOtp, LoginWithOtp …
│  ├─ Engagement/               ToggleLike, ToggleFavorite, SendInterest, RespondToInterest …
│  ├─ Chat/                     SendMessage, MarkConversationRead, UnsendMessage …
│  ├─ Broker/                   CreateManagedProfile, ExportManagedProfile, ClaimManagedProfile …
│  │  ├─ Import/                StoreImportBatch, FixImportRow, AttestBatchConsent …
│  │  ├─ Staff/                 InviteStaff, DeactivateStaff …
│  │  └─ Payouts/               CreatePayoutRun, ApprovePayoutRun …
│  └─ Admin/{Area}/             SuspendMember, ApproveProfile, RefundOrder …
├─ Contracts/                   # interfaces for infrastructure: SmsGateway, PaymentGateway, …
├─ Data/{Domain}/               # readonly DTOs: ProfileSearchCriteria, MessageData, ImportRowData
├─ Domain/{Domain}/             # pure domain logic reused by Actions: MatchScorer, AgeRule,
│                               #   PhotoVisibilityResolver, InterestStateMachine, BureauGate
├─ Enums/                       # ProfileStatus, InterestStatus, BureauRole, PlanCode, …
├─ Events/{Domain}/             # broadcast + domain events: MessageSent, ProfileLiked, …
├─ Exceptions/{Domain}/         # QuotaExceeded, BrokerKycRequired, ProfileNotManagedByBroker …
├─ Http/
│  ├─ Controllers/{Public,Member,Broker,Admin,Webhooks}/   # thin: downloads, webhooks only
│  ├─ Middleware/               EnsurePhoneVerified, EnsureBrokerActive, EnsureTwoFactor …
│  └─ Requests/                 # only for controller endpoints (webhooks/downloads)
├─ Jobs/{Domain}/               GenerateDailyMatches, ParseImportBatch, PurgeVerificationDocs …
├─ Listeners/{Domain}/          AttributeCommission (on OrderPaid) …
├─ Livewire/
│  ├─ Public/                   QuickRegister, ContactForm
│  ├─ Member/{Area}/            Auth/, Onboarding/, Profile/, Search/, Matches/, Engagement/,
│  │                            Chat/, Notifications/, Verification/, Settings/, Billing/
│  ├─ Broker/{Area}/            Dashboard, ManagedProfiles/, Import/, Staff/, Earnings …
│  ├─ Admin/{Area}/             Members/, Moderation/, Verification/, Chat/, Billing/, Brokers/ …
│  ├─ Shared/                   LikeButton, FavoriteButton, InterestButton, NotificationBell,
│  │                            ChatBadge, ToastStack
│  └─ Forms/{Domain}/           Livewire Form Objects: BasicForm, CareerForm, ManagedProfileForm
├─ Models/                      # Eloquent models, flat (Profile, Interest, Broker, BrokerStaff …)
│  └─ Concerns/                 HasProfileCode, BelongsToBureau, Auditable …
├─ Notifications/{Domain}/      InterestReceived, ProfileApproved, CommissionEarned …
├─ Observers/                   MasterDataObserver (cache flush), AuditLogObserver (immutability)
├─ Policies/                    ProfilePolicy, ConversationPolicy, ManagedProfilePolicy …
├─ Providers/
├─ Queries/{Domain}/            # complex read queries: ProfileSearchQuery, BureauProfilesQuery
├─ Rules/                       # custom validation rules: MinimumMarriageAge, CasteBelongsToReligion
├─ Services/{Area}/             # infrastructure implementations: Sms/Msg91Gateway,
│                               #   Payments/RazorpayGateway, Pdf/BiodataPdfRenderer
├─ Support/                     # tiny framework-level helpers only (no business rules)
└─ ValueObjects/                Money, PhoneNumber, HeightCm, ProfileCode

config/        oppam.php (non-secret app config), pagenav.php, entitlements defaults, horizon.php …
database/
├─ factories/  one per model, with states: ->active(), ->premium(), ->brokerManaged()
├─ migrations/ YYYY_MM_DD_HHMMSS_verb_noun.php (create_interests_table, add_import_batch_id_to_profiles)
└─ seeders/    MastersSeeder, PlansSeeder, DemoProfilesSeeder, DemoBrokersSeeder, AdminRolesSeeder
resources/
├─ css/        tokens.css (design tokens), template/{style,responsive}.css, admin.css
├─ js/         app.js, echo.js, alpine/{composer,toast,online-store}.js, template/{drawer,carousels}.js
└─ views/
   ├─ components/            # Blade UI kit (see oppam-ui-standards): ui/button, ui/card, profile/row …
   ├─ layouts/               public, member, broker, admin + partials/
   ├─ livewire/{public,member,broker,admin,shared}/…   # mirrors app/Livewire
   ├─ pages/                 # static Blade pages (about, privacy …)
   ├─ pdf/                   biodata, invoice, payout-statement
   ├─ mail/                  markdown mail views
   └─ errors/                404, 403, 419, 500, 503
routes/        web.php, broker.php, admin.php, channels.php, webhooks.php, legacy.php, console.php
tests/
├─ Unit/{Domain}/            pure logic (no DB): MatchScorerTest, AgeRuleTest
├─ Feature/{Domain}/         Actions, policies, jobs, webhooks, controllers
├─ Livewire/{Surface}/{Area}/ component tests
├─ Broadcasting/             channel auth + event payload tests
├─ Browser/                  Dusk journeys
└─ Pest.php, TestCase.php, Support/ (helpers: actingAsMember(), actingAsBureau(role))
docs/          PRD, template-notes, progress.md, decisions.md, build-prompts.md
```

## Naming

| Thing | Convention | Example |
|---|---|---|
| Action | `VerbNoun`, method `handle()` | `SendInterest::handle(Profile $from, Profile $to, ?string $note)` |
| Livewire component | Noun or NounVerb by screen | `Member\Chat\Thread`, `Broker\Import\BatchReview` |
| Livewire view | kebab path mirroring class | `livewire/member/chat/thread.blade.php` |
| Blade component | kebab, grouped folder | `<x-ui.button>`, `<x-profile.row>` |
| Event | Past tense | `MessageSent`, `InterestAccepted`, `ImportProgressed` |
| Job | Imperative | `GenerateDailyMatches`, `ParseImportBatch` |
| Notification | What happened, for the recipient | `InterestReceived`, `ManagedProfileApproved` |
| Enum | Singular noun, cases PascalCase, values UPPER_SNAKE | `InterestStatus::Accepted = 'ACCEPTED'` |
| Route name | `surface.area.action` | `member.messages.show`, `broker.import.review`, `admin.brokers.index` |
| Permission key (admin) | `area.action` | `brokers.payout`, `verification.document.view` |
| Bureau permission | `area.action` | `profiles.import`, `client_inbox.respond` |
| DB table | plural snake | `broker_import_rows` |
| Boolean column | `is_` / `has_` / past-participle timestamp | `is_verified`, `claimed_at` |
| Money column | `_paise` suffix | `subtotal_paise` |
| Test | describes behaviour | `it('refuses an interest when the monthly quota is used up')` |

## Boundaries between surfaces

- Member, Broker and Admin **share** Models, Actions, Domain, Services, Policies.
- They **never share** Livewire components (except `Livewire/Shared`) or layouts.
- Admin code never calls member Livewire components and vice-versa.
- Anything a broker and a member both do (e.g. creating a profile) is **one Action** called from
  two components — never two copies (e.g. `CreateManagedProfile` reuses the wizard Form Objects
  and `ProfileRules`).

## Growth rules

- A folder with more than ~15 files gets sub-folders by sub-area.
- A class over ~200 lines or a method over ~25 lines is a refactor candidate; over 400 / 50 is a
  review blocker unless justified.
- A Livewire component over ~150 lines is doing too much — split into child components or move
  logic into Actions.
- Circular dependencies between domains are not allowed; use events for "when X happens in Billing,
  Broker should react".
