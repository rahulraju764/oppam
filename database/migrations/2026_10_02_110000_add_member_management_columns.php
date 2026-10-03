<?php

declare(strict_types=1);

use App\Enums\ProfileStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A03 member management (P1.7a):
| - users.suspended_at — set while an admin suspension is in force (survives a delete, so a restore
|   puts a suspended member back to suspended); users.anonymised_at — the purge ran (stage two of
|   deletion, 30 days after the soft delete).
| - profiles.previous_status — the status before an admin suspended / hid / deleted the profile, so
|   reactivate / unhide / restore put it back (never ACTIVE for a profile that was never approved).
| - subscriptions.paused_at — a suspension pauses the plan; reactivation moves ends_at on by the
|   paused time.
| - member_notes — internal admin notes on a member, append-only (no update / delete path).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->timestamp('anonymised_at')->nullable()->after('deleted_at');
            $table->index(['deleted_at', 'anonymised_at']);   // the purge job's scan
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('previous_status', 20)->nullable()->after('status');
        });

        EnumCheck::add('profiles', 'previous_status', ProfileStatus::class, nullable: true);

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable()->after('ends_at');
        });

        Schema::create('member_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Cascade: the only hard delete of a user is RegisterMember releasing a never-verified
            // registration (members are otherwise soft-deleted, then anonymised — never removed).
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('admin_label', 150)->nullable();       // the admin's email at the time
            $table->string('body', 2000);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notes');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `profiles` DROP CONSTRAINT `chk_profiles_previous_status`');
        }

        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn('previous_status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at', 'anonymised_at']);
            $table->dropColumn(['suspended_at', 'anonymised_at']);
        });
    }
};
