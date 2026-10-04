<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved searches (PRD §7.2, §10 M04).
 * Members can save up to 10 searches with an alert frequency ('OFF', 'DAILY', 'WEEKLY').
 * The SendSavedSearchAlerts job queries alertable searches using (alert_frequency, last_alerted_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('filters');
            $table->enum('alert_frequency', ['OFF', 'DAILY', 'WEEKLY'])->default('DAILY');
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id']);
            $table->index(['alert_frequency', 'last_alerted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
