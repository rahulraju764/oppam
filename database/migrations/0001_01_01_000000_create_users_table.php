<?php

declare(strict_types=1);

use App\Enums\CreatedFor;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Members and brokers (web guard, PRD §7.2 / §8.1). ULID keys; the mobile number (E.164) is the
| primary login id. Edited in place in P0.4 (nothing deployed yet — docs/decisions.md). Staff
| (admin guard) live in admin_users (P0.5).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('phone', 16)->unique();                  // E.164, R-M01-1 one account per mobile
            $table->string('email')->nullable()->unique();          // R-M01-1 unique if given
            $table->string('password');
            $table->string('role', 20)->default(UserRole::Member->value);
            $table->string('created_for', 20)->default(CreatedFor::Self->value);
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();          // presence (PRD §9.6)
            $table->string('status', 20)->default(UserStatus::Active->value);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'status']);
        });

        EnumCheck::add('users', 'role', UserRole::class);
        EnumCheck::add('users', 'created_for', CreatedFor::class);
        EnumCheck::add('users', 'status', UserStatus::class);

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->ulid('user_id')->nullable()->index();          // not a FK: admin sessions share the table
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
