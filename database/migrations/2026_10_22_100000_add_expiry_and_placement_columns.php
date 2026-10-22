<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P5.2 (M10 expiry, §7.3 highlight / top placement):
|  - subscriptions.reminded_7d_at / reminded_1d_at: each expiry reminder is sent once;
|  - profiles.top_placement_until: Diamond "top placement in search", denormalised like
|    highlighted_until (Gold / Diamond "highlighted in search") and is_premium, so search never
|    resolves a plan per row (SyncMembershipFlags keeps all three in step with subscriptions).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('reminded_7d_at')->nullable()->after('cancelled_at');
            $table->timestamp('reminded_1d_at')->nullable()->after('reminded_7d_at');
            $table->index(['status', 'ends_at']);   // expiry sweep + reminder windows
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->timestamp('top_placement_until')->nullable()->after('highlighted_until');
        });

        // The Relevance score must stay an index-only scan (P2.1, measured at 100k): the cover index
        // gains top_placement_until — 16 columns, the MySQL / MariaDB maximum.
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_search_cover_index');
            $table->index(self::coverColumns(withTopPlacement: true), 'profiles_search_cover_index');
        });
    }

    /** @return list<string> */
    private static function coverColumns(bool $withTopPlacement): array
    {
        return ['status', 'gender', 'dob', 'height_cm', 'marital_status', 'religion_id', 'caste_id',
            'mother_tongue_id', 'star_id', 'district_id', 'is_premium', 'is_verified', 'highlighted_until',
            ...($withTopPlacement ? ['top_placement_until'] : []), 'published_at', 'last_active_at'];
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_search_cover_index');
            $table->index(self::coverColumns(withTopPlacement: false), 'profiles_search_cover_index');
        });
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn('top_placement_until');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'ends_at']);
            $table->dropColumn(['reminded_7d_at', 'reminded_1d_at']);
        });
    }
};
