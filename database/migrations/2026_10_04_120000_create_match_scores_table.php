<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match scores cache (PRD §7.2, §10 M05).
 * Caches the 60/25/10/5 score breakdown between two profiles:
 * - preference_fit (0..60)
 * - reverse_fit (0..25)
 * - activity_score (0..10)
 * - completeness_score (0..5)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_scores', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('target_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('preference_fit')->default(0);
            $table->unsignedTinyInteger('reverse_fit')->default(0);
            $table->unsignedTinyInteger('activity_score')->default(0);
            $table->unsignedTinyInteger('completeness_score')->default(0);
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['source_profile_id', 'target_profile_id']);
            $table->index(['source_profile_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_scores');
    }
};
