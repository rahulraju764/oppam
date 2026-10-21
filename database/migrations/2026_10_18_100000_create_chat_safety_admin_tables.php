<?php

declare(strict_types=1);

use App\Enums\EngagementSignalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Chat safety, admin side (P4.3b, PRD A13, docs/decisions.md 2026-10-18):
| - chat_safety_rules: the rules editor's OVERRIDES of SafetyRule's defaults (a missing row = the
|   default pattern and actions; "reset" deletes the row).
| - engagement_signals: now decided by a moderator (status, decision, note, who, when) and counted
|   while open (occurrences, last_raised_at) instead of a new row per repeat.
| - member_restrictions: the one-click "pause sending" on a signal — likes, favourites, interests,
|   requests and chat messages refused until `until` (or lifted).
| - break_glass_grants: a super admin's time-limited, reasoned read of one conversation.
| - conversation_participants.unanswered_count: messages this member sent in a row without a reply
|   (one-sided messaging signal, Overview).
| - interests.sent_at index: the hourly interest-spam detector reads a window of sends.
| ULID keys; additive.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_safety_rules', function (Blueprint $table): void {
            $table->string('key', 20)->primary();
            $table->text('pattern')->nullable();                 // null: the rule has no pattern (A11 lists / settings)
            $table->string('early_action', 10);
            $table->string('later_action', 10);
            $table->foreignUlid('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('engagement_signals', function (Blueprint $table): void {
            $table->string('status', 12)->default(EngagementSignalStatus::Open->value)->after('details');
            $table->unsignedInteger('occurrences')->default(1)->after('status');
            $table->timestamp('last_raised_at')->nullable()->after('occurrences');
            $table->string('decision', 20)->nullable()->after('last_raised_at');
            $table->string('decision_note', 500)->nullable()->after('decision');
            $table->foreignUlid('decided_by_admin_id')->nullable()->after('decision_note')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by_admin_id');

            $table->index(['status', 'created_at']);       // the Signals list and its badge
        });

        Schema::create('member_restrictions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamp('until')->nullable();            // always set (nullable: MariaDB adds ON UPDATE to a NOT NULL timestamp)
            $table->string('reason', 500)->nullable();
            $table->foreignUlid('signal_id')->nullable()->constrained('engagement_signals')->nullOnDelete();
            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignUlid('lifted_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['profile_id', 'until']);        // "is this member paused?" on every send
            $table->index(['until']);
        });

        Schema::create('break_glass_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('reference_type', 10);
            $table->string('reference', 100);
            $table->timestamp('expires_at')->nullable();       // always set (see member_restrictions.until)
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);   // the member's data-access log
            $table->index(['admin_user_id', 'expires_at']);
            $table->index(['created_at']);
        });

        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->unsignedInteger('unanswered_count')->default(0)->after('unread_count');
            $table->index(['unanswered_count']);
        });

        Schema::table('interests', function (Blueprint $table): void {
            $table->index(['sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('interests', function (Blueprint $table): void {
            $table->dropIndex(['sent_at']);
        });
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropIndex(['unanswered_count']);
            $table->dropColumn('unanswered_count');
        });
        Schema::dropIfExists('break_glass_grants');
        Schema::dropIfExists('member_restrictions');
        Schema::table('engagement_signals', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('decided_by_admin_id');
            $table->dropColumn(['status', 'occurrences', 'last_raised_at', 'decision', 'decision_note', 'decided_at']);
        });
        Schema::dropIfExists('chat_safety_rules');
    }
};
