<?php

declare(strict_types=1);

use App\Enums\MessageFlagStatus;
use App\Enums\ReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Chat safety (P4.3a, PRD A13 + M09 report, docs/decisions.md 2026-10-16):
| - reports: a member reporting another. Shared with P6.2 (profile reports, A10). From chat it
|   names the thread and the selected messages (report_messages).
| - message_flags: the A13 queue — one row per reason a message was flagged (ContentSafety as it
|   was sent, or a member's report). `excerpt` keeps only what was masked / matched, ENCRYPTED
|   (model cast), shown to a moderator on an audited reveal.
| - messages.removed_by_admin_at: "This message was removed by Oppam"; messages.body_hash: the
|   repeated-message (spam) check across threads.
| - conversations.frozen_at / frozen_reason: why a thread is FROZEN (R-M07-5).
| ULID keys; rows go with their message / thread (cascade). Additive.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reporter_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('reported_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('source', 10);
            $table->string('reason', 30);
            $table->string('details', 500)->nullable();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default(ReportStatus::Open->value);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['reported_profile_id', 'source', 'created_at']);    // the auto-freeze count
            $table->index(['reporter_profile_id', 'reported_profile_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('report_messages', function (Blueprint $table): void {
            $table->foreignUlid('report_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            $table->primary(['report_id', 'message_id']);
        });

        Schema::create('message_flags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sender_profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('source', 10);
            $table->string('rule', 20);
            $table->text('excerpt')->nullable();
            $table->foreignUlid('report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('reporter_profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('status', 12)->default(MessageFlagStatus::Pending->value);
            $table->string('decision', 20)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignUlid('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);       // the queue and its count
            $table->index(['message_id', 'status']);
            $table->index(['sender_profile_id', 'created_at']);
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('removed_by_admin_at')->nullable()->after('deleted_for_all_at');
            $table->char('body_hash', 64)->nullable()->after('body');
            $table->index(['sender_profile_id', 'body_hash', 'created_at'], 'messages_sender_hash_index');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('frozen_at')->nullable()->after('status');
            $table->string('frozen_reason', 20)->nullable()->after('frozen_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['frozen_at', 'frozen_reason']);
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_sender_hash_index');
            $table->dropColumn(['removed_by_admin_at', 'body_hash']);
        });
        Schema::dropIfExists('message_flags');
        Schema::dropIfExists('report_messages');
        Schema::dropIfExists('reports');
    }
};
