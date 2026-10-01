<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Engagement tables needed by the profile page (PRD §7.2, M03):
| - blocks: symmetric and silent — a blocked pair never sees each other (R-M03-1). The block /
|   unblock screens arrive in P6.2; the table and the BlockList check are needed now.
| - profile_views: one row per viewer / viewed / IST day, `count` bumped on repeat views
|   ("who viewed me", M15).
| - contact_views: one row per viewer → viewed pair; a contact reveal is charged once (R-M03-2).
| ULID keys (CLAUDE.md), profile rows cascade on hard delete.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('blocker_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('blocked_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('reason', 50)->nullable();
            $table->timestamps();

            $table->unique(['blocker_profile_id', 'blocked_profile_id']);
            $table->index(['blocked_profile_id']);     // "is anyone blocking me?" direction
        });

        Schema::create('profile_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('viewer_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('viewed_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->date('view_date');                  // IST date
            $table->unsignedSmallInteger('count')->default(1);
            $table->timestamps();

            $table->unique(['viewer_profile_id', 'viewed_profile_id', 'view_date'], 'profile_views_pair_day_unique');
            $table->index(['viewed_profile_id', 'updated_at']);
        });

        Schema::create('contact_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('viewer_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('viewed_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['viewer_profile_id', 'viewed_profile_id']);
            $table->index(['viewed_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_views');
        Schema::dropIfExists('profile_views');
        Schema::dropIfExists('blocks');
    }
};
