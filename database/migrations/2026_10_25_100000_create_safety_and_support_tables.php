<?php

declare(strict_types=1);

use App\Enums\AbuseCaseStatus;
use App\Enums\BannedIdentifierType;
use App\Enums\CaseOutcome;
use App\Enums\CasePriority;
use App\Enums\TicketAuthor;
use App\Enums\TicketCategory;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Safety & support (P6.2, PRD M09 / A10 / F07, docs/decisions.md 2026-10-23):
| - abuse_cases: one OPEN case per reported profile — `open_key` holds the profile id while the
|   case is open and NULL once closed, so the unique index allows exactly one open case (MySQL has
|   no partial indexes). Priority P0/P1/P2, SLA due time, escalation, legal hold.
| - reports.abuse_case_id: every report (chat or profile) joins its case. The `reports` table is
|   the PRD's `abuse_reports` (shared with A13).
| - safety_actions: the outcome of each case — append-only (no updated_at).
| - banned_identifiers: the ban blocklist (email / phone / device as HMAC hashes, photo pHash as is).
| - profiles.legal_hold_at: purges skip a profile under legal hold.
| - support_tickets / support_ticket_messages / support_canned_responses: the A10 support desk.
| ULID keys; public codes CASE10001 / TKT10001 (URLs use codes). Additive.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 12)->unique();                                   // CASE10001
            $table->foreignUlid('reported_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->char('open_key', 26)->nullable()->unique();                     // = reported_profile_id while OPEN
            $table->string('status', 10)->default(AbuseCaseStatus::Open->value);
            $table->string('priority', 2);
            $table->timestamp('sla_due_at')->nullable();                     // always set; nullable so MariaDB adds no ON UPDATE CURRENT_TIMESTAMP
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedSmallInteger('reports_count')->default(0);
            $table->unsignedSmallInteger('reporters_count')->default(0);
            $table->timestamp('last_reported_at')->nullable();
            $table->foreignUlid('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('outcome', 20)->nullable();
            $table->string('outcome_note', 500)->nullable();
            $table->foreignUlid('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('paged_at')->nullable();                              // P0 paging sent (once per case)
            $table->timestamps();

            $table->index(['status', 'priority', 'sla_due_at']);                   // the queue
            $table->index(['reported_profile_id', 'created_at']);
        });
        EnumCheck::add('abuse_cases', 'status', AbuseCaseStatus::class);
        EnumCheck::add('abuse_cases', 'priority', CasePriority::class);
        EnumCheck::add('abuse_cases', 'outcome', CaseOutcome::class, nullable: true);

        Schema::table('reports', function (Blueprint $table): void {
            $table->foreignUlid('abuse_case_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
        });

        Schema::create('safety_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('abuse_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('outcome', 20);
            $table->string('note', 500)->nullable();
            $table->json('details')->nullable();                                    // counts only (messages removed, photos …)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['profile_id', 'created_at']);
        });
        EnumCheck::add('safety_actions', 'outcome', CaseOutcome::class);

        Schema::create('banned_identifiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 10);
            $table->string('value', 64);                                            // HMAC-SHA256 hex, or the 16-hex pHash
            $table->foreignUlid('profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignUlid('abuse_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('banned_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'value']);
        });
        EnumCheck::add('banned_identifiers', 'type', BannedIdentifierType::class);

        Schema::table('profiles', function (Blueprint $table): void {
            $table->timestamp('legal_hold_at')->nullable();
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 12)->unique();                                   // TKT10001
            $table->string('source', 15);
            $table->string('category', 12);
            $table->string('status', 16)->default(TicketStatus::Open->value);
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('subject', 150);
            $table->json('context')->nullable();                                    // in-app help: plan + last actions
            $table->foreignUlid('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('first_response_due_at')->nullable();          // always set (see sla_due_at)
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'first_response_due_at']);                    // the queue + SLA
            $table->index(['user_id', 'created_at']);
        });
        EnumCheck::add('support_tickets', 'source', TicketSource::class);
        EnumCheck::add('support_tickets', 'category', TicketCategory::class);
        EnumCheck::add('support_tickets', 'status', TicketStatus::class);

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('author', 10);
            $table->foreignUlid('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);                         // internal notes: never shown to the member
            $table->timestamp('created_at')->useCurrent();

            $table->index(['support_ticket_id', 'created_at']);
        });
        EnumCheck::add('support_ticket_messages', 'author', TicketAuthor::class);

        Schema::create('support_canned_responses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title', 100);
            $table->text('body');
            $table->string('category', 12)->nullable();
            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
        EnumCheck::add('support_canned_responses', 'category', TicketCategory::class, nullable: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_canned_responses');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn('legal_hold_at');
        });
        Schema::dropIfExists('banned_identifiers');
        Schema::dropIfExists('safety_actions');
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('abuse_case_id');
        });
        Schema::dropIfExists('abuse_cases');
    }
};
