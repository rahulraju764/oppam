<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| F06: the 05:00 fan-out prunes batches older than 31 days (`match_date < ?`) in batches; this
| index keeps that from scanning the whole table (≈ 10 rows × members × 31 days).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_matches', function (Blueprint $table): void {
            $table->index('match_date', 'daily_matches_match_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('daily_matches', function (Blueprint $table): void {
            $table->dropIndex('daily_matches_match_date_index');
        });
    }
};
