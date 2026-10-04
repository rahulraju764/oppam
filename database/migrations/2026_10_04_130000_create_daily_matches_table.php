<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily match recommendations (PRD §10 M05, §12 F06).
 * Generated daily at 05:00 IST for active members, up to 10 hand-picked profiles.
 * Expires at 23:59:59 IST.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_matches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('matched_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->date('match_date'); // IST date
            $table->unsignedTinyInteger('score')->default(0);
            $table->boolean('is_viewed')->default(false);
            $table->boolean('is_interacted')->default(false);
            $table->timestamps();

            $table->unique(['profile_id', 'matched_profile_id', 'match_date']);
            $table->index(['profile_id', 'match_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_matches');
    }
};
