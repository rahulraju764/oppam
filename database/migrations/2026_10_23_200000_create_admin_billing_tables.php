<?php

declare(strict_types=1);

use App\Enums\GstSplit;
use App\Enums\InvestigationStatus;
use App\Enums\InvestigationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Admin billing (P5.3, PRD A06). Money in paise; restrict-on-delete (financial records).
|  - orders.paid_payment_id: the payment that activated the order (set by ActivateSubscription;
|              back-filled here with the earliest captured payment of each paid order). A refund of
|              THAT payment can end the plan and gets a credit note; a refund of any other captured
|              payment (extra / mismatched) never touches the plan and has no invoice to credit.
|  - refunds: one per refund of one payment, full or partial; REQUESTED by one admin and decided by
|              ANOTHER (every refund, decisions 2026-10-23); `effects_applied_at` makes the one-time
|              side effects (credit note, plan ended, commission voided) idempotent between the
|              approval and the gateway's webhook.
|  - credit_notes: gapless per Indian financial year, own series (CN-2627-000001, ≤ 16 characters),
|              GST split copied from the invoice it credits.
|  - billing_investigations: the reconciliation job's "investigate" rows; `fingerprint` (type +
|              the gateway id) makes a re-run add nothing twice.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignUlid('paid_payment_id')->nullable()->unique()->after('paid_at')->constrained('payments')->restrictOnDelete();
        });

        // Back-fill: the earliest captured payment of each paid order is the one that activated it.
        DB::table('orders')->where('status', OrderStatus::Paid->value)->whereNull('paid_payment_id')->orderBy('id')->chunkById(500, function ($orders): void {
            foreach ($orders as $order) {
                $payment = DB::table('payments')->where('order_id', $order->id)->where('status', PaymentStatus::Captured->value)
                    ->orderBy('captured_at')->orderBy('id')->value('id');
                if ($payment !== null) {
                    DB::table('orders')->where('id', $order->id)->update(['paid_payment_id' => $payment]);
                }
            }
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 12)->unique();                       // RFD100001
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('profile_id')->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->unsignedInteger('amount_paise');                    // what goes back, GST included
            $table->boolean('ends_plan')->default(false);              // refunds the rest of the activating payment
            $table->string('reason', 500);
            $table->string('status', 20)->default(RefundStatus::Requested->value);
            $table->foreignUlid('requested_by_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignUlid('decided_by_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('gateway_refund_id', 64)->nullable()->unique();
            $table->string('failure_reason', 60)->nullable();           // a code, never gateway text
            $table->timestamp('effects_applied_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);                    // A06 refunds lists, work queue
            $table->index(['order_id', 'status']);                      // one open refund per order
            $table->index(['payment_id', 'status']);                    // refundable balance of a payment
        });
        EnumCheck::add('refunds', 'status', RefundStatus::class);
        EnumCheck::add('refunds', 'category', RefundCategory::class);

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('number', 16)->unique();                     // GST rule 46: ≤ 16 characters
            $table->string('financial_year', 4);
            $table->unsignedInteger('sequence');
            $table->foreignUlid('refund_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('profile_id')->constrained()->restrictOnDelete();
            $table->timestamp('issued_at')->useCurrent();               // explicit default: MariaDB implicit ON UPDATE
            $table->string('gst_split', 12);
            $table->unsignedInteger('taxable_paise');
            $table->unsignedSmallInteger('gst_rate_bps');
            $table->unsignedInteger('cgst_paise')->default(0);
            $table->unsignedInteger('sgst_paise')->default(0);
            $table->unsignedInteger('igst_paise')->default(0);
            $table->unsignedInteger('total_paise');
            $table->timestamps();

            $table->unique(['financial_year', 'sequence']);
            $table->index('issued_at');                                 // monthly GST export
        });
        EnumCheck::add('credit_notes', 'gst_split', GstSplit::class);

        Schema::create('billing_investigations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 20);
            $table->string('status', 10)->default(InvestigationStatus::Open->value);
            $table->string('fingerprint', 100)->unique();
            $table->foreignUlid('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('gateway_payment_id', 64)->nullable();
            $table->json('details');                                    // amounts and codes only, no payer details
            $table->foreignUlid('resolved_by_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
        EnumCheck::add('billing_investigations', 'type', InvestigationType::class);
        EnumCheck::add('billing_investigations', 'status', InvestigationStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_investigations');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('refunds');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_payment_id');
        });
    }
};
