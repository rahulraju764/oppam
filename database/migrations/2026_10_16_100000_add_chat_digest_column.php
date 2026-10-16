<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Chat part 2 (P4.2, PRD M07 / M08 `new_message` offline digest): how far a member's unread messages
| in a thread were already put in a digest email, so each waiting message is announced at most once.
| The retention job (R-M07-6) reads conversations.last_message_at (indexed since P3.1). Additive.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->ulid('digested_up_to_message_id')->nullable()->after('last_read_message_id');
            $table->index(['unread_count', 'is_muted'], 'participants_digest_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropIndex('participants_digest_index');
            $table->dropColumn('digested_up_to_message_id');
        });
    }
};
