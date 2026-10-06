<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| In-app notifications (PRD §10 M08): Laravel's database-notification table, with ULID notifiables
| (users have ULID keys) and `type` = the NotificationEvent value (MemberNotification::databaseType),
| so the /notifications filters are a plain `type IN (...)` within one member's rows (the feed
| index narrows to the member first). Pruned after 180 days (R-M08-4).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 40);
            $table->ulidMorphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_id', 'read_at'], 'notifications_unread_index');
            $table->index(['notifiable_id', 'created_at'], 'notifications_feed_index');
            $table->index('created_at');   // the 180-day prune
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
