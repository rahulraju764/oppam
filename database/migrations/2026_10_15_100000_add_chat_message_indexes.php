<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Chat core (P4.1, PRD M07): one member's messages in one thread — the Free member's reply count
| (R-M07-3) and "mark the other member's messages read" (MarkConversationRead) both filter on it.
| Additive only.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'sender_profile_id', 'read_at'], 'messages_thread_sender_read_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_thread_sender_read_index');
        });
    }
};
