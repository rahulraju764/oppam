# Phase 5 (Money) — owner review pack

CLAUDE.md rule 5: payment and refund code needs **your own line-by-line review** before it is
committed, even after the reviewer says READY TO COMMIT. This pack is that review, in order:
read the code (part 1), confirm the open decisions (part 2), try it in the browser (part 3).
When all three are done, say "commit" and P5.2, P5.3 and Phase 5 get ticked in `docs/progress.md`.

Phase 5 code is already on `phase-1` (owner commits 337a900 and c33c990, 2026-11-01/02). The
**2026-11-03 close-out** (P5.3's open Minors and your three answers) is uncommitted; its files are
marked **(11-03)** below.

---

## Part 1 — read the money path (≈ 45 min)

Read in this order. "Look for" says what must be true; if any line isn't, stop and tell Claude.

### 1. The price is the server's (M10, §7.3)
| File | Look for |
|---|---|
| `app/Domain/Billing/PriceCalculator.php` | Integers in paise only; GST 18 % half-up on the price after any coupon. |
| `app/Actions/Billing/QuoteCheckout.php`, `app/Actions/Billing/CreateOrder.php` | The amount comes from the plan key + months + coupon read from the DB. Nothing the browser sends can change it. Rate limit `billing.checkouts_max_per_hour`. |
| `app/Livewire/Member/Billing/Checkout.php` | Public properties are the plan key, months and coupon code, never an amount. |

### 2. Razorpay tells us a payment happened (webhook)
| File | Look for |
|---|---|
| `routes/webhooks.php`, `app/Http/Controllers/Webhooks/RazorpayWebhookController.php` | No CSRF exemption without a signature check; the signature is verified on the raw body **before** anything is parsed. |
| `app/Actions/Billing/HandlePaymentWebhook.php`, `app/Services/Payments/RazorpayWebhookPayload.php` | A replayed event (same event id / payment id) does nothing the second time. |
| `app/Actions/Billing/ActivateSubscription.php` | A capture whose amount differs from the order total is **held** (stuck order), never activated. Activation, the invoice and the plan run in one transaction. |
| `app/Actions/Billing/IssueInvoice.php` | Invoice numbers come from a locked counter inside that transaction, so they are gapless (`OPM-<FY>-000001`). |

### 3. Plans change and end (P5.2)
| File | Look for |
|---|---|
| `app/Services/Entitlements/EntitlementService.php` | Limits are read from the subscription in force, so a plan's end takes effect at once. |
| `app/Jobs/Billing/ExpireSubscriptions.php`, `app/Actions/Billing/SyncMembershipFlags.php` | Premium / highlight / top-placement flags are cleared when the plan ends; the job is idempotent. |
| `app/Jobs/Billing/SendPlanExpiryReminders.php` | 7-day and 1-day reminders, once each. |

### 4. Admin money actions (P5.3, A06)
| File | Look for |
|---|---|
| `app/Actions/Admin/Billing/ResolveStuckOrder.php` **(11-03)** | Force-activate needs the `billing.force_activate` key **and** the super_admin role, a reason, a captured payment **covering the order total** (a short payment is refused), and none of it refunded. |
| `app/Actions/Admin/Billing/ManageRefund.php` **(11-03)** | One admin requests, a **different** one approves. Approval claims the refund under a row lock before Razorpay is called, so two approvers can't both send it. Each of the three hand-closes (**it was sent / it wasn't sent / it failed at Razorpay**) first **asks Razorpay** for the payment's refunds. "Not sent" also waits `billing.refund_not_sent_wait_minutes` (30) after approval. |
| `app/Actions/Billing/SettleRefund.php` **(11-03)** | Side effects only at PROCESSED (money known to be back), once. A late report that disagrees raises an "investigate" row. |
| `app/Actions/Billing/ApplyRefundEffects.php`, `app/Actions/Billing/IssueCreditNote.php`, `app/Domain/Billing/RefundBalance.php` | Credit notes never total more than the invoice; reserved money counts as unavailable; a refund that returns the rest of the activating payment ends the plan. |
| `app/Jobs/Billing/ReconcilePayments.php`, `app/Models/BillingInvestigation.php` **(11-03)** | 02:00 IST. A refund stuck again after it was settled gets its row back. A row you resolved stays resolved. |
| `app/Queries/Admin/RevenueSummary.php` **(11-03)** | A02 tiles and the by-plan chart are both net of processed refunds of the paying payment. |

### 5. The gateway itself
| File | Look for |
|---|---|
| `app/Contracts/PaymentGateway.php` **(11-03)** | The new `refundsForPayment()`. |
| `app/Services/Payments/RazorpayGateway.php` **(11-03)** | The real SDK calls. **These have never run against Razorpay** (no account yet, so this is for P9.3 / P9.5). The new refund lookup is `GET /payments/{id}/refunds`. |
| `app/Providers/AppServiceProvider.php` (payments block) | `PAYMENT_DRIVER=fake` throws in production. |

### 6. Broker commission link (§11A B.21)
`app/Listeners/Broker/VoidReferralOnRefund.php`: a processed refund of the payment that paid the
order voids the commission and claws it back on the next payout run.

---

## Part 2 — decisions waiting for you

Answered by you on 2026-11-03 (built and tested; confirm you're happy with how they turned out):

1. **"It wasn't sent"** first asks Razorpay. If Razorpay has this refund, that is recorded and the
   close is refused. The button also stays disabled for 30 minutes after approval
   (`billing.refund_not_sent_wait_minutes`, 10–1440, A15 setting).
   "It was sent" checks the id at Razorpay: it must be paid, for this amount, on this payment, and
   not another refund's.
2. **Featured mark** shows on Search and All profiles only (PRD §7.3 "highlighted in search").
3. **A short payment can't be force-activated.** It is refunded instead; the member pays again
   or gets a complimentary grant.

Claude's extension of (1), **please confirm**:

4. **"It failed at Razorpay"** asks Razorpay too, and refuses if Razorpay shows the refund paid or
   pending. Without this, closing a refund that Razorpay had actually paid would free the money,
   and a retry would refund twice (the same bug as (1)).

Claude's defaults from 2026-11-01 (`docs/decisions.md`, row "Admin billing (P5.3, A06)"),
**please confirm or change**:

5. (3) A refund's side effects run only when the money is known back (PROCESSED).
6. (4) `ends_plan` = the refund returns the rest of the activating payment; partial refunds keep
   the plan; credit notes split each GST head proportionally and never exceed the invoice.
7. (5) A refund of an **extra** payment has no credit note, doesn't touch the plan or A02 revenue,
   and **doesn't void the broker commission**. This narrows your 2026-10-24 "any refund voids".
8. (6) No answer from Razorpay keeps the money reserved until a person closes it. An accepted
   refund can't be closed as "not sent". After 48 h with no webhook it is closed as paid or failed.
9. (7) Re-poll and mark failed need `billing.refund`; force-activate needs `billing.force_activate`
   + super_admin; complimentary grants need `billing.force_activate`.
10. (8)–(12) The stuck-order definition, reconciliation window, 48 h stuck rule, the GST CSV with
    codes only (no names), and A02 revenue computed on read, GST included.

---

## Part 3 — try it in the browser (≈ 30 min)

Start XAMPP MySQL, then `composer dev`. The payment gateway is the fake one; no money moves.
Admin panel: http://admin.localhost:8000 (`admin@oppam.test` = super admin,
`finance@oppam.test` = finance; password `ADMIN_SEED_PASSWORD` from your `.env`). To make a
member, register at http://localhost:8000/register; the OTP is in `storage/logs/laravel.log`
(`SMS_DRIVER=log`).

**Member**
1. `/plans` → Gold, 3 months → the checkout shows the price, GST and total. Enter a coupon (make
   one in step 6 first) and press **Apply**.
2. **Complete test payment** → the success page → download the invoice PDF. Check the GST state code
   and that the invoice says it isn't a valid tax invoice while the GSTIN is a placeholder.
3. `/billing` → the order, invoice download, usage meters.
4. *(If two of your members are connected)* open their chat as the Free one: the composer is locked.
   Pay in another tab and the composer unlocks without a reload. (Dusk `CheckoutLiveTest` proves
   this automatically.)
5. `/search` → a Gold / Diamond member shows **Featured**. Bookmark them; `/favorites` shows them
   without the mark.

**Admin** (sign in as `finance@oppam.test`)

6. Billing → Coupons → create a 10 % coupon.
7. Billing → Plans → Gold: change a price and watch the live card preview. Set a limit in the
   entitlement matrix that doesn't match the feature text, and the consistency warning appears.
8. Billing → Orders → open the member's order: the timeline and the raw webhook payloads.
9. **Refund (two admins):** on the order, request a ₹100 refund. Sign out, sign in as
   `admin@oppam.test`, go to Billing → Refunds → **Approve**. It is processed at once (fake
   gateway) and a credit note is issued. As the member, `/billing` shows the refund, and its credit
   note downloads.
10. **The double-refund guard (11-03):** in a terminal run
    `php artisan tinker --execute="app(App\Services\Payments\FakePaymentGateway::class)->nextRefund('sent-unanswered')"`,
    then request and approve another ₹100 (two admins as in step 9). The approval warns that the
    gateway didn't answer. On Refunds → Processing:
    - **It wasn't sent** is disabled, with the time it becomes available.
    - To skip the 30-minute wait, set `billing.refund_not_sent_wait_minutes` to 10 in the `settings`
      table, then run `php artisan cache:clear`. (A15 has no screen yet; it comes in P8.4.)
    - When it's enabled, press it. It's refused: "Razorpay has this refund … so it was sent".
11. **Short payment (11-03):** start a checkout as the member and leave it unpaid. Then make the
    fake gateway capture ₹1 less, in `php artisan tinker`:
    ```php
    $o = App\Models\Order::query()->where('status', 'CREATED')->latest()->first();
    $d = app(App\Services\Payments\FakePaymentGateway::class)->capture($o->gateway_order_id, $o->total_paise - 100);
    app(App\Actions\Billing\HandlePaymentWebhook::class)->handle($d['body'], $d['signature'], $d['event_id']);
    ```
    As super admin, Billing → Stuck orders → open it (`amount_mismatch`) → **Force-activate**. It is
    refused, showing the amount captured and the order total. Refund it from the same page instead.
12. Billing → GST export for this month: invoices and negative credit notes, profile codes, no names.
13. Dashboard (A02): today's / this month's revenue and the by-plan chart, both lower by the refunds
    from steps 9–10.

---

## Not checkable here (stays open, recorded in `docs/progress.md`)

- **Real Razorpay, end to end:** test-mode keys, webhooks, the refund calls and the new refund
  lookup. Needs the Razorpay account (outside task), at P9.3 / P9.5.
- **MySQL 8:** CI (`.github/workflows/ci.yml`) runs the suite on MySQL 8 when `phase-1` is pushed.
  Check that the latest GitHub Actions run is green; `gh` isn't installed on this PC.
- **At launch (P9.5):** live keys; the seller's legal name, GSTIN, address and SAC; subscribe the
  Razorpay webhook to `refund.created`, `refund.processed` and `refund.failed`; run
  `php artisan db:seed --class=MastersSeeder` once for `master_states.gst_code`.
