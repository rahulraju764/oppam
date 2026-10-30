<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7.8b — the A07 export audit's "burst" check counts one login's exports in the hour before each
| row (§11A B.12 anomaly highlighting): an index on (user_id, created_at) serves that lookup, and
| (created_at) the all-bureaus list's newest-first order.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_profile_exports', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('broker_profile_exports', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            // The user_id foreign key may be using the composite index: give it its own first.
            $table->index('user_id');
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
