<?php

declare(strict_types=1);

use App\Enums\BrokerSignal;
use App\Enums\ReferralVoidReason;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P7.8c — fraud & quality signals (§11A B.12 signal table; owner 2026-10-29: capture the data now):
|  - referrals: how the referred member signed up — the IP and its /24 (/48) network as keyed HMACs
|    (never the raw address) and the device as sha256 of the `oppam_device` cookie, the same scheme
|    as login_events, so a bureau referring itself can be matched against its own logins;
|  - broker_fraud_signals: one OPEN row per (bureau, signal type), refreshed by the daily scan,
|    resolved by an admin with a note;
|  - import batches: signal flags (shared mobile patterns, photos matching other profiles).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_referrals', function (Blueprint $table): void {
            $table->char('signup_ip_hash', 64)->nullable()->after('void_reason');
            $table->char('signup_net_hash', 64)->nullable()->after('signup_ip_hash');
            $table->char('signup_device_hash', 64)->nullable()->after('signup_net_hash');
            $table->index(['broker_id', 'signup_ip_hash']);
            $table->index(['broker_id', 'signup_net_hash']);
            $table->index(['broker_id', 'signup_device_hash']);
        });

        Schema::create('broker_fraud_signals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->string('type', 32);                                           // BrokerSignal
            $table->json('details')->nullable();                                  // codes and counts only
            $table->char('open_key', 60)->nullable()->unique();                   // "{broker}:{type}" while OPEN
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('resolution_note', 300)->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'resolved_at']);
            $table->index(['resolved_at', 'type']);
        });
        EnumCheck::add('broker_fraud_signals', 'type', BrokerSignal::class);

        // "Void all" after a fraud signal: FRAUD joins the void reasons.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `broker_referrals` DROP CONSTRAINT `chk_broker_referrals_void_reason`');
            EnumCheck::add('broker_referrals', 'void_reason', ReferralVoidReason::class, nullable: true);
        }

        Schema::table('broker_import_batches', function (Blueprint $table): void {
            $table->json('signal_flags')->nullable()->after('stop_reason');
        });
    }

    public function down(): void
    {
        Schema::table('broker_import_batches', function (Blueprint $table): void {
            $table->dropColumn('signal_flags');
        });

        Schema::dropIfExists('broker_fraud_signals');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('broker_referrals')->where('void_reason', 'FRAUD')->update(['void_reason' => 'REFUND']);
            DB::statement('ALTER TABLE `broker_referrals` DROP CONSTRAINT `chk_broker_referrals_void_reason`');
            DB::statement("ALTER TABLE `broker_referrals` ADD CONSTRAINT `chk_broker_referrals_void_reason` CHECK (`void_reason` IN ('REFUND') OR `void_reason` IS NULL)");
        }

        Schema::table('broker_referrals', function (Blueprint $table): void {
            $table->dropIndex(['broker_id', 'signup_ip_hash']);
            $table->dropIndex(['broker_id', 'signup_net_hash']);
            $table->dropIndex(['broker_id', 'signup_device_hash']);
            $table->dropColumn(['signup_ip_hash', 'signup_net_hash', 'signup_device_hash']);
        });
    }
};
