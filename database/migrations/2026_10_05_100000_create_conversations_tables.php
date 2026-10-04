<?php

declare(strict_types=1);

use App\Enums\ConversationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Chat threads (PRD §7.2, M07) — schema only in P3.1, so the chat channels (§9.3) can be authorised
| and tested now. One thread per pair (`pair_key` = the two profile ids sorted, "a|b"). The
| interest_id foreign key is added with the interests table (P3.4); messages come in P4.1.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('interest_id')->nullable();               // FK added in P3.4 (interests table)
            $table->string('pair_key', 60)->unique();
            $table->ulid('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('status', 20)->default(ConversationStatus::Open->value);
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('profile_id')->constrained('profiles');
            $table->ulid('last_read_message_id')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_muted')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->unique(['conversation_id', 'profile_id']);
            $table->index(['profile_id', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
