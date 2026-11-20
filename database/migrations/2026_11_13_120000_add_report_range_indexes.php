<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9.1b (A09 Revenue + Trust & ops): indexes for the day / period range scans the new
 * calculators (RevenueCalculator, TrustOpsCalculator) and live queries (LiveOps) run nightly,
 * on every settle / backfill day and on uncached report views. The existing composite indexes
 * lead with status / profile, so a range on the decision / timestamp column alone can't use them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_items', fn (Blueprint $table) => $table->index('decided_at'));
        Schema::table('verification_requests', fn (Blueprint $table) => $table->index('decided_at'));
        Schema::table('abuse_cases', fn (Blueprint $table) => $table->index('decided_at'));
        Schema::table('reports', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->index('ends_at'));
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->index('created_at');
            $table->index('first_responded_at');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropIndex(['resolved_at']);
            $table->dropIndex(['first_responded_at']);
            $table->dropIndex(['created_at']);
        });
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropIndex(['ends_at']));
        Schema::table('reports', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('abuse_cases', fn (Blueprint $table) => $table->dropIndex(['decided_at']));
        Schema::table('verification_requests', fn (Blueprint $table) => $table->dropIndex(['decided_at']));
        Schema::table('moderation_items', fn (Blueprint $table) => $table->dropIndex(['decided_at']));
    }
};
