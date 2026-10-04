<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P2.1 search (M04):
| - ignores: "don't show me this profile" (M09). One-directional, silent — unlike a block, the other
|   member is unaffected. Search excludes ignored profiles now; the Ignore button comes with P6.2.
| - Sort indexes for the Newest and Last-active orders, equality columns first (status, gender)
|   then the sort column — the existing (status, gender, dob / religion / district) indexes cover
|   the main filters.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignores', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ignorer_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('ignored_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['ignorer_profile_id', 'ignored_profile_id']);
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->index(['status', 'gender', 'published_at'], 'profiles_search_newest_index');
            // published_at is the tie-breaker of every sort: in the index, "Last active" never reads rows.
            $table->index(['status', 'gender', 'last_active_at', 'published_at'], 'profiles_search_active_index');
            // Covers the Relevance score and the common filters, so ranking every candidate is an
            // index-only scan (no row reads) — measured with profiles:search-benchmark at 100k.
            $table->index(['status', 'gender', 'dob', 'height_cm', 'marital_status', 'religion_id', 'caste_id',
                'mother_tongue_id', 'star_id', 'district_id', 'is_premium', 'is_verified', 'highlighted_until', 'published_at', 'last_active_at'],
                'profiles_search_cover_index');
        });

        $this->privacyIndex();
    }

    /** Separate so up() stays readable: the incognito exclusion reads only the few incognito rows. */
    private function privacyIndex(): void
    {
        Schema::table('privacy_settings', function (Blueprint $table): void {
            // Every profile gets a privacy row (onboarding), so the incognito anti-join must not scan them all.
            $table->index(['incognito', 'profile_id'], 'privacy_settings_incognito_index');
        });
    }

    public function down(): void
    {
        Schema::table('privacy_settings', function (Blueprint $table): void {
            $table->dropIndex('privacy_settings_incognito_index');
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_search_newest_index');
            $table->dropIndex('profiles_search_active_index');
            $table->dropIndex('profiles_search_cover_index');
        });

        Schema::dropIfExists('ignores');
    }
};
