<?php

declare(strict_types=1);

use App\Enums\ImpersonationEndReason;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A01 / A03 time-boxed impersonation (P1.7b). One row per "Impersonate" click: who, whom, why;
| the single-use handoff token (SHA-256 only, cleared once used) and the IP it may be used from;
| when the member session started and when it must end (started_at + 30 min); how it ended.
| Start and end are also audit rows (audit_logs is the permanent record).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('admin_label', 150);                       // the admin's email at the time
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->char('token_hash', 64)->nullable()->unique();     // null once used
            $table->timestamp('token_expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();             // the admin's IP; the handoff must come from it
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'ended_at']);   // "this admin's live session"
            $table->index(['user_id', 'ended_at']);         // "is this member being impersonated?"
        });

        EnumCheck::add('impersonation_sessions', 'end_reason', ImpersonationEndReason::class, nullable: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
