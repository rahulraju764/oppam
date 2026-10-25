<?php

declare(strict_types=1);

use App\Enums\ReferralVoidReason;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P7.2 — the commission ledger (B.5, B.10): when a referral was earned, on what base and at which
| rate (snapshots: an admin may change brokers.commission_bps later), and when / why it was voided
| (R-M13-5 — a PAID row keeps its payout_item_id so the next payout run claws it back, B.19).
| The A07 unverified-codes queue reads profiles by (broker_verified, broker_code_raw).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_referrals', function (Blueprint $table): void {
            $table->unsignedInteger('base_paise')->nullable()->after('order_id');           // orders.taxable_paise (owner 2026-10-24)
            $table->unsignedSmallInteger('commission_bps')->nullable()->after('base_paise');
            $table->timestamp('earned_at')->nullable()->after('payout_item_id');
            $table->timestamp('voided_at')->nullable()->after('earned_at');
            $table->string('void_reason', 40)->nullable()->after('voided_at');
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->index(['broker_verified', 'broker_code_raw']);
        });

        EnumCheck::add('broker_referrals', 'void_reason', ReferralVoidReason::class, nullable: true);
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `broker_referrals` DROP CONSTRAINT `chk_broker_referrals_void_reason`');
        }

        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex(['broker_verified', 'broker_code_raw']);
        });

        Schema::table('broker_referrals', function (Blueprint $table): void {
            $table->dropColumn(['base_paise', 'commission_bps', 'earned_at', 'voided_at', 'void_reason']);
        });
    }
};
