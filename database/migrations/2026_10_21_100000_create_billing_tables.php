<?php

declare(strict_types=1);

use App\Enums\CouponType;
use App\Enums\GstSplit;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Billing (P5.1, PRD M10 / F04 / A06). Money in paise; every amount on an order is computed on
| the server from a plan price row (+ coupon + GST) and frozen on the order when it is created.
|  - orders:   one checkout attempt; `code` (ORD100001) is the only id in URLs; the gateway's order
|              id is unique so a webhook finds exactly one order.
|  - payments: one row per gateway payment; the UNIQUE gateway_payment_id is what makes a replayed
|              or duplicated webhook harmless.
|  - invoices: one per paid order; `number` is gapless per Indian financial year (allocated inside
|              the activation transaction), seller / buyer details frozen at issue.
|  - coupons / coupon_redemptions: A06 coupon rules (admin screens in P5.3).
|  - payment_webhook_events: every signature-valid webhook, unique per gateway event id (A06 shows
|              the payloads; a redelivery is recognised and skipped). Payer email / phone / UPI id /
|              card details are redacted before storing.
| Rows here are financial records: restrict-on-delete everywhere (a purged member's profile row
| stays — PurgeDeletedMember anonymises it).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 30)->unique();                       // stored upper-case
            $table->string('type', 10);
            $table->unsignedInteger('value');                           // PERCENT: 1–100 · FIXED: paise
            $table->unsignedInteger('max_discount_paise')->nullable();  // cap for a percent coupon
            $table->unsignedInteger('min_order_paise')->nullable();     // pre-GST plan price
            $table->unsignedInteger('max_redemptions')->nullable();     // all members together
            $table->unsignedSmallInteger('per_member_limit')->default(1);
            $table->boolean('first_time_only')->default(false);        // members who never paid before
            $table->json('plan_codes')->nullable();                     // null = every paid plan
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->timestamps();
        });
        EnumCheck::add('coupons', 'type', CouponType::class);

        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->foreignUlid('profile_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('plan_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('plan_price_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('duration_months');
            $table->foreignUlid('coupon_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('subtotal_paise');
            $table->unsignedInteger('discount_paise')->default(0);
            $table->unsignedInteger('taxable_paise');
            $table->unsignedSmallInteger('gst_rate_bps');
            $table->unsignedInteger('gst_paise');
            $table->unsignedInteger('total_paise');
            $table->char('currency', 3)->default('INR');
            $table->string('status', 20)->default(OrderStatus::Created->value);
            $table->string('gateway', 20);
            $table->string('gateway_order_id', 64)->nullable()->unique();
            $table->string('failure_reason', 60)->nullable();          // a code, never gateway text
            $table->timestamp('expires_at')->useCurrent();              // explicit default: MariaDB implicit ON UPDATE
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status', 'created_at']);     // "my open order", billing history
            $table->index(['status', 'expires_at']);                    // expiry sweep, stuck orders (A06)
        });
        EnumCheck::add('orders', 'status', OrderStatus::class);

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->string('gateway_payment_id', 64)->unique();
            $table->unsignedInteger('amount_paise');
            $table->char('currency', 3)->default('INR');
            $table->string('method', 30)->nullable();                   // upi / card / netbanking / wallet
            $table->string('status', 20);
            $table->string('error_code', 60)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
        EnumCheck::add('payments', 'status', PaymentStatus::class);

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('number', 16)->unique();                     // GST rule 46: ≤ 16 characters
            $table->string('financial_year', 4);                        // "2627" = FY 2026-27
            $table->unsignedInteger('sequence');
            $table->foreignUlid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('profile_id')->constrained()->restrictOnDelete();
            $table->timestamp('issued_at')->useCurrent();
            $table->json('seller');                                     // frozen at issue
            $table->json('buyer');                                      // frozen at issue
            $table->string('gst_split', 12);
            $table->unsignedInteger('taxable_paise');
            $table->unsignedSmallInteger('gst_rate_bps');
            $table->unsignedInteger('cgst_paise')->default(0);
            $table->unsignedInteger('sgst_paise')->default(0);
            $table->unsignedInteger('igst_paise')->default(0);
            $table->unsignedInteger('total_paise');
            $table->string('pdf_path')->nullable();                     // private disk
            $table->timestamps();

            $table->unique(['financial_year', 'sequence']);
            $table->index(['profile_id', 'issued_at']);
        });
        EnumCheck::add('invoices', 'gst_split', GstSplit::class);

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('profile_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('discount_paise');
            $table->timestamps();

            $table->index(['coupon_id', 'profile_id']);                 // per-member limit
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('gateway', 20);
            $table->string('event_id', 64);
            $table->string('event', 60);
            $table->longText('payload');                                // the body, payer details redacted
            $table->string('gateway_order_id', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 40)->nullable();                  // activated / duplicate / ignored / unknown_order …
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
            $table->index('gateway_order_id');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignUlid('order_id')->nullable()->unique()->after('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('duration_months')->nullable()->after('source');
            $table->timestamp('cancelled_at')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn(['duration_months', 'cancelled_at']);
        });
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
    }
};
