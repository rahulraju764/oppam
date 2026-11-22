<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A12 webhook status & replay (P9.2b2): a stored payment event whose processing threw is marked —
| how many attempts failed, when last, and the exception CLASS (never its message) — so the health
| panel can list it and an admin with `system.webhooks.replay` can replay it (who / when kept here,
| the reason in the audit log). dateTime, not timestamp (TimestampColumnsTest: no MariaDB ON UPDATE).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->unsignedSmallInteger('failed_attempts')->default(0)->after('outcome');
            $table->dateTime('last_failed_at')->nullable()->after('failed_attempts');
            $table->string('last_error', 120)->nullable()->after('last_failed_at');
            $table->dateTime('replayed_at')->nullable()->after('last_error');
            $table->foreignUlid('replayed_by')->nullable()->after('replayed_at')->constrained('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replayed_by');
            $table->dropColumn(['failed_attempts', 'last_failed_at', 'last_error', 'replayed_at']);
        });
    }
};
