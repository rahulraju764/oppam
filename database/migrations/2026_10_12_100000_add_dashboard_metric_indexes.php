<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Indexes for the hourly KPI snapshot (PRD A02, TakeMetricSnapshot) — each counts rows inside one
| IST day, on tables that had no index starting with that date: without these the hourly run
| would scan `likes` and `messages` end to end. Also the admin sidebar's moderation counts, which
| group waiting items by status and type on every admin page (WorkQueues).
| Additive only (expand step): no column or constraint changes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('likes', function (Blueprint $table): void {
            $table->index('created_at', 'likes_created_at_index');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['created_at', 'type'], 'messages_created_at_type_index');
        });

        Schema::table('interest_events', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'interest_events_status_created_at_index');
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->index('published_at', 'profiles_published_at_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index(['role', 'created_at'], 'users_role_created_at_index');
            $table->index(['role', 'last_seen_at'], 'users_role_last_seen_at_index');
        });

        Schema::table('moderation_items', function (Blueprint $table): void {
            $table->index(['status', 'type', 'submitted_at'], 'moderation_items_status_type_submitted_index');
        });
    }

    public function down(): void
    {
        Schema::table('moderation_items', fn (Blueprint $table) => $table->dropIndex('moderation_items_status_type_submitted_index'));
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_role_last_seen_at_index');
            $table->dropIndex('users_role_created_at_index');
        });
        Schema::table('profiles', fn (Blueprint $table) => $table->dropIndex('profiles_published_at_index'));
        Schema::table('interest_events', fn (Blueprint $table) => $table->dropIndex('interest_events_status_created_at_index'));
        Schema::table('messages', fn (Blueprint $table) => $table->dropIndex('messages_created_at_type_index'));
        Schema::table('likes', fn (Blueprint $table) => $table->dropIndex('likes_created_at_index'));
    }
};
