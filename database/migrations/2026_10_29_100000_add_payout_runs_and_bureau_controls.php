<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7.8a — payout runs and A07 bureau controls (§11A B.10, B.12, R-A07-1…5, B.17, B.19; decisions 2026-10-29):
|  - payout items: the balance carried in from an earlier line, TDS, which later line took over a
|    carried balance, and a unique UTR (one bank transfer = one line);
|  - payout runs: the audited reason when a run overlaps a period already paid (B.17);
|  - ledger: the payout line that clawed back a commission voided after it was paid (B.19 — once);
|  - brokers: payouts held (fraud signal "hold commission") and the admin who created the bureau
|    (an admin-created bureau waits for its Owner to apply with the same mobile).
| Statements are rendered on demand from these rows (like invoices) — no stored PDF.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_payout_items', function (Blueprint $table): void {
            $table->integer('carried_in_paise')->default(0)->after('clawback_paise');
            $table->unsignedInteger('tds_paise')->default(0)->after('carried_in_paise');
            $table->foreignUlid('rolled_into_item_id')->nullable()->after('carried_forward_paise')
                ->constrained('broker_payout_items')->nullOnDelete();
            $table->unique('utr');
            $table->string('status', 16)->default('PENDING')->change();   // P7.1 made it 10: CARRIED_FORWARD is 15
        });

        Schema::table('broker_payout_runs', function (Blueprint $table): void {
            $table->string('code', 12)->nullable()->unique()->after('id');      // PAY1001 — URLs and statements
            $table->string('override_reason', 300)->nullable()->after('status');
            $table->index(['status', 'created_at']);
        });

        Schema::table('broker_referrals', function (Blueprint $table): void {
            $table->foreignUlid('clawed_back_item_id')->nullable()->after('payout_item_id')
                ->constrained('broker_payout_items')->nullOnDelete();
            $table->index(['status', 'payout_item_id']);
        });

        Schema::table('brokers', function (Blueprint $table): void {
            $table->timestamp('payouts_held_at')->nullable()->after('imports_paused_batch_id');
            $table->string('payouts_hold_reason', 300)->nullable()->after('payouts_held_at');
            $table->foreignUlid('created_by_admin_id')->nullable()->after('activated_at')
                ->constrained('admin_users')->nullOnDelete();
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('brokers', function (Blueprint $table): void {
            $table->dropIndex(['phone']);
            $table->dropConstrainedForeignId('created_by_admin_id');
            $table->dropColumn(['payouts_held_at', 'payouts_hold_reason']);
        });

        Schema::table('broker_referrals', function (Blueprint $table): void {
            $table->dropIndex(['status', 'payout_item_id']);
            $table->dropConstrainedForeignId('clawed_back_item_id');
        });

        Schema::table('broker_payout_runs', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'override_reason']);
        });

        Schema::table('broker_payout_items', function (Blueprint $table): void {
            $table->dropUnique(['utr']);
            $table->string('status', 10)->default('PENDING')->change();
            $table->dropConstrainedForeignId('rolled_into_item_id');
            $table->dropColumn(['carried_in_paise', 'tds_paise']);
        });
    }
};
