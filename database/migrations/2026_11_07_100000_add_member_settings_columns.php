<?php

declare(strict_types=1);

use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| P8.2a — /settings (PRD M14, owner decisions 2026-11-07):
|  - `privacy_settings.hide_from_search` (every plan): out of search, matches, alerts and the home
|    page. `incognito` keeps only "browse without appearing in who viewed me" and is Diamond-only
|    (plan_features `incognito`). Members who were incognito stay hidden: their rows are copied.
*/
return new class extends Migration
{
    /** The entitlement values before this migration (for down()). */
    private const OLD_ENTITLEMENTS = [
        'likes_per_day', 'favorites_total', 'interests_per_month', 'contact_views_per_month',
        'free_replies_per_conversation', 'chat_send', 'see_who_liked_me', 'see_who_viewed_me',
        'search_highlight', 'top_placement',
    ];

    /** Both columns that store an Entitlement carry its CHECK: a fresh database and a migrated one must match. */
    private const ENTITLEMENT_TABLES = ['plan_features', 'entitlement_usages'];

    public function up(): void
    {
        Schema::table('privacy_settings', function (Blueprint $table): void {
            $table->boolean('hide_from_search')->default(false)->after('incognito');
        });
        DB::table('privacy_settings')->where('incognito', true)->update(['hide_from_search' => true]);

        Schema::table('privacy_settings', function (Blueprint $table): void {
            // Search's anti-join reads only the few hidden rows (it used to read the incognito ones).
            $table->index(['hide_from_search', 'profile_id'], 'privacy_settings_hide_from_search_index');
            $table->dropIndex('privacy_settings_incognito_index');
        });

        $this->replaceEntitlementCheck();
        $this->addIncognitoFeature();
    }

    public function down(): void
    {
        DB::table('plan_features')->where('entitlement', Entitlement::Incognito->value)->delete();
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $values = collect(self::OLD_ENTITLEMENTS)->map(fn (string $v): string => DB::getPdo()->quote($v))->implode(', ');
            foreach (self::ENTITLEMENT_TABLES as $table) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `chk_{$table}_entitlement`");
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `chk_{$table}_entitlement` CHECK (`entitlement` IN ({$values}))");
            }
        }

        Schema::table('privacy_settings', function (Blueprint $table): void {
            $table->index(['incognito', 'profile_id'], 'privacy_settings_incognito_index');
            $table->dropIndex('privacy_settings_hide_from_search_index');
        });
        Schema::table('privacy_settings', function (Blueprint $table): void {
            $table->dropColumn('hide_from_search');
        });
    }

    private function replaceEntitlementCheck(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::ENTITLEMENT_TABLES as $table) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `chk_{$table}_entitlement`");
                EnumCheck::add($table, 'entitlement', Entitlement::class);
            }
        }
    }

    /** Existing plans get the row (PlansSeeder is insert-only and seeds it for new databases): on for Diamond only. */
    private function addIncognitoFeature(): void
    {
        $now = now();
        DB::table('plans')->get(['id', 'code'])->each(function (object $plan) use ($now): void {
            $exists = DB::table('plan_features')->where('plan_id', $plan->id)->where('entitlement', Entitlement::Incognito->value)->exists();
            if (! $exists) {
                DB::table('plan_features')->insert([
                    'id' => (string) Str::ulid(),
                    'plan_id' => $plan->id,
                    'entitlement' => Entitlement::Incognito->value,
                    'limit_value' => null,
                    'is_enabled' => $plan->code === PlanCode::Diamond->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
};
