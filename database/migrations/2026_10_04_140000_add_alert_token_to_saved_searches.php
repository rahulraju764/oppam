<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Saved-search alert emails (M04): the unsubscribe link carries this random token instead of the
| saved search's ULID (URLs never carry ULIDs). Existing rows get one here; new rows get one from
| the model.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_searches', function (Blueprint $table): void {
            $table->string('alert_token', 64)->nullable()->after('alert_frequency');
        });

        DB::table('saved_searches')->whereNull('alert_token')->orderBy('id')->each(function (object $row): void {
            DB::table('saved_searches')->where('id', $row->id)->update(['alert_token' => Str::random(48)]);
        });

        Schema::table('saved_searches', function (Blueprint $table): void {
            $table->string('alert_token', 64)->nullable(false)->change();
            $table->unique('alert_token');
        });
    }

    public function down(): void
    {
        Schema::table('saved_searches', function (Blueprint $table): void {
            $table->dropUnique(['alert_token']);
            $table->dropColumn('alert_token');
        });
    }
};
