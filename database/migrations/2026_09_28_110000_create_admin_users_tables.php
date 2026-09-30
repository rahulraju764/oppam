<?php

declare(strict_types=1);

use App\Enums\AdminStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Admin staff, invitations and the session registry (PRD A01, §8.1).
| - admin_users: separate from members; TOTP secret + recovery codes stored ENCRYPTED; a
|   2FA lock (5 wrong codes) is account-level. Password-attempt lockouts are per email in the
|   rate limiter so unknown emails lock the same way (no account enumeration).
| - admin_invitations: 72 h single-use links; only the SHA-256 of the token is stored.
| - admin_sessions: one row per signed-in admin session; the row id is kept in that (server-side)
|   session, so sessions can be listed and revoked even when the session store is Redis.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status', 20)->default(AdminStatus::Active->value);
            $table->text('two_factor_secret')->nullable();            // encrypted cast
            $table->text('two_factor_recovery_codes')->nullable();    // encrypted JSON of hashes
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedTinyInteger('failed_two_factor_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->foreignUlid('invited_by_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
        EnumCheck::add('admin_users', 'status', AdminStatus::class);

        Schema::create('admin_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('email');
            $table->string('name', 100);
            $table->string('role', 60);
            $table->string('token_hash', 64)->unique();
            $table->foreignUlid('invited_by_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('expires_at')->useCurrent();   // explicit default: see docs/decisions.md (MariaDB implicit ON UPDATE)
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'accepted_at']);
        });

        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at')->useCurrent();   // explicit default: see docs/decisions.md (MariaDB implicit ON UPDATE)
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
        Schema::dropIfExists('admin_invitations');
        Schema::dropIfExists('admin_users');
    }
};
