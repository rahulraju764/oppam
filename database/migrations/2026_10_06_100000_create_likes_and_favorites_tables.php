<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Likes and favourites (PRD §7.2, M06):
| - likes: "I like your profile", one row per direction (unique pair, R-M06-1); `seen_at` = the
|   recipient has seen it in "Liked me". A mutual like is simply both directions existing.
| - favorites: a private bookmark with an optional private note — never shown to the target and
|   never notified (R-M06-6).
| - engagement_signals: abuse signals raised by engagement guards (like spam R-M06-2 now; interest
|   spam, mass favourites … later) for the A13 monitors (P4.3). Append-only.
| ULID keys; rows go with a hard-deleted profile.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('likes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('from_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('to_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['from_profile_id', 'to_profile_id']);
            $table->index(['to_profile_id', 'created_at']);       // "Liked me", newest first
            $table->index(['from_profile_id', 'created_at']);     // "I liked", newest first
        });

        Schema::create('favorites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();            // the owner
            $table->foreignUlid('favorite_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'favorite_profile_id']);
            $table->index(['profile_id', 'created_at']);          // /favorites, newest first
        });

        Schema::create('engagement_signals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('details')->nullable();                  // counts and windows only — never PII
            $table->timestamp('created_at')->nullable();

            $table->index(['type', 'created_at']);
            $table->index(['profile_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_signals');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('likes');
    }
};
