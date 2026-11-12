<?php

declare(strict_types=1);

use App\Enums\SignupSource;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * P9.1a (A09 reports): data the reports need that was never recorded (owner 2026-11-12: capture the
 * cheap ones from now on — no backfill is possible, the reports say "tracked since").
 *  - users.signup_source / signup_utm: where a member came from (organic, a bureau, a marketing link);
 *    existing members are ORGANIC unless a bureau referred them (profiles.broker_id).
 *  - member_activity_days: one row per member per IST day they were active (DAU / WAU / MAU, cohorts).
 *  - search_logs: anonymous — which filter KEYS a search used, how many results, the sort; no values,
 *    no member id.
 *  - indexes for the nightly / range scans the reports run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('signup_source', 12)->default(SignupSource::Organic->value)->after('created_for');
            $table->json('signup_utm')->nullable()->after('signup_source');
            $table->index(['signup_source', 'created_at']);
            $table->index('phone_verified_at');
        });
        EnumCheck::add('users', 'signup_source', SignupSource::class);

        // Existing members that a bureau referred (P7.2 sets profiles.broker_id on a resolved code).
        DB::table('users')
            ->whereIn('id', DB::table('profiles')->whereNotNull('broker_id')->whereNotNull('user_id')->select('user_id'))
            ->update(['signup_source' => SignupSource::Broker->value]);

        Schema::create('member_activity_days', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');   // IST
            $table->primary(['user_id', 'day']);
            $table->index('day');
        });

        Schema::create('search_logs', function (Blueprint $table): void {
            $table->id();
            $table->date('day');   // IST
            $table->json('filters');   // list of filter keys used, never their values
            $table->unsignedInteger('result_count');
            $table->string('sort', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('day');
        });

        Schema::table('orders', fn (Blueprint $table) => $table->index('paid_at'));
        Schema::table('payments', fn (Blueprint $table) => $table->index('captured_at'));
        Schema::table('interests', fn (Blueprint $table) => $table->index('responded_at'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->index(['profile_id', 'starts_at']));
        Schema::table('conversations', fn (Blueprint $table) => $table->index('created_at'));   // conversations started in a period
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropIndex(['profile_id', 'starts_at']));
        Schema::table('interests', fn (Blueprint $table) => $table->dropIndex(['responded_at']));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['captured_at']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(['paid_at']));
        Schema::dropIfExists('search_logs');
        Schema::dropIfExists('member_activity_days');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `users` DROP CONSTRAINT `chk_users_signup_source`');
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone_verified_at']);
            $table->dropIndex(['signup_source', 'created_at']);
            $table->dropColumn(['signup_source', 'signup_utm']);
        });
    }
};
