<?php

declare(strict_types=1);

use App\Enums\Flag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * P8.4 (A15): flags and settings the app now really reads (owner 2026-11-12).
 *  - likes.enabled, chat.images, realtime.enabled guard features that already run. The seeder
 *    wrote every flag OFF; now that the app obeys them, a row no admin ever toggled
 *    (updated_by_id NULL) is set to the flag's default (ON), so the upgrade switches nothing off.
 *    A row an admin set stays as they set it.
 *  - photos.max_upload_mb: the seeded 8 never applied (uploads used config: 10 MB). A row still
 *    holding the seeded 8 with no admin edit becomes 10, so behaviour doesn't change.
 * No down(): both are data corrections; rolling back the code makes the rows unread again.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Flag::cases() as $flag) {
            if ($flag->defaultOn()) {
                DB::table('feature_flags')->where('key', $flag->value)->whereNull('updated_by_id')->update(['is_enabled' => true]);
            }
        }

        DB::table('settings')->where('key', 'photos.max_upload_mb')->whereNull('updated_by_id')
            ->where('value', json_encode(8))->update(['value' => json_encode(10)]);
    }

    public function down(): void {}
};
