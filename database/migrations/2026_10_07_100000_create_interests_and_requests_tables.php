<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Interests and requests (PRD §7.2, M06, F02):
| - interests: a formal proposal, ONE row per direction (unique pair). A re-send after a
|   withdrawal, an expiry or the decline cooldown reuses the row (`sent_at` = the current send);
|   the history is in interest_events.
| - interest_events: what happened to an interest, in order (sent / accepted / declined /
|   withdrawn / expired) and who did it. Append-only.
| - conversations.interest_id → the accepted interest that opened the thread (the column exists
|   since P3.1; the key is added now that interests exist).
| - messages: PRD §7.2 as is, created now only so accepting an interest can leave the SYSTEM
|   message "You are now connected" (owner decision 2026-10-07). Chat itself is P4.1.
|   `sender_profile_id` is nullable: a SYSTEM message has no sender.
| - access_requests: "may I see your photos / horoscope?" — one table, two types (owner decision
|   2026-10-07; the PRD names photo_requests without columns). One row per type and pair.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('from_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('to_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->string('message', 300)->nullable();
            // dateTime, not timestamp: MariaDB gives a second NOT NULL timestamp an invalid implicit default.
            $table->dateTime('sent_at');
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['from_profile_id', 'to_profile_id']);
            $table->index(['to_profile_id', 'status', 'sent_at']);       // Interests → Received
            $table->index(['from_profile_id', 'status', 'sent_at']);     // Interests → Sent
            $table->index(['status', 'expires_at']);                     // the hourly expiry job
        });

        Schema::create('interest_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('interest_id')->constrained('interests')->cascadeOnDelete();
            $table->string('status', 20);
            $table->foreignUlid('actor_profile_id')->nullable()->constrained('profiles')->nullOnDelete();   // null = the system (expiry)
            $table->timestamp('created_at')->nullable();

            $table->index(['interest_id', 'created_at']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreign('interest_id')->references('id')->on('interests')->nullOnDelete();
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();                               // time-sortable: the chat cursor
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('sender_profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->uuid('client_id');                                   // optimistic-UI de-dupe key
            $table->string('type', 10)->default('TEXT');
            $table->text('body')->nullable();
            $table->string('status', 10)->default('SENT');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->timestamp('deleted_for_all_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'client_id']);
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('access_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 20);                                  // PHOTO | HOROSCOPE
            $table->foreignUlid('requester_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('owner_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'requester_profile_id', 'owner_profile_id'], 'access_requests_pair_unique');
            $table->index(['owner_profile_id', 'status', 'created_at']);
            $table->index(['requester_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
        Schema::dropIfExists('messages');
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['interest_id']);
        });
        Schema::dropIfExists('interest_events');
        Schema::dropIfExists('interests');
    }
};
