<?php

declare(strict_types=1);

use App\Enums\ImportsPauseReason;
use App\Enums\ImportStopReason;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P7.7 — bulk upload steps 3–6 (§11A B.9, decisions 2026-10-28):
|  - batch: when the import started, why the last run stopped early, the skipped count, and the
|    A04 tally of its submitted profiles (approval rate in the history, auto-pause R-M13-32);
|  - `fix_sheet_path`: the re-uploaded errors file (private, encrypted, purged with the batch);
|  - brokers: why imports are paused and which batch tripped the auto-pause.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_import_batches', function (Blueprint $table): void {
            $table->string('fix_sheet_path', 100)->nullable()->after('photos_zip_path');
            $table->json('zip_names')->nullable()->after('fix_sheet_path');   // lowercase photo names in the zip, for re-checks
            $table->unsignedSmallInteger('rows_skipped')->default(0)->after('rows_imported');
            $table->unsignedSmallInteger('rows_submitted')->default(0)->after('rows_skipped');
            $table->unsignedSmallInteger('rows_approved')->default(0)->after('rows_submitted');
            $table->unsignedSmallInteger('rows_rejected')->default(0)->after('rows_approved');
            $table->timestamp('import_started_at')->nullable()->after('consent_attested_by_user_id');
            $table->string('stop_reason', 20)->nullable()->after('import_started_at');

            $table->index(['status', 'created_at']);   // purge job: batches past retention with files
        });

        Schema::table('brokers', function (Blueprint $table): void {
            $table->string('imports_paused_reason', 10)->nullable()->after('imports_paused_at');
            $table->foreignUlid('imports_paused_batch_id')->nullable()->after('imports_paused_reason')
                ->constrained('broker_import_batches')->nullOnDelete();
        });

        // Daily allowance (R-M13-31): rows of one bureau's batches imported today.
        Schema::table('broker_import_rows', function (Blueprint $table): void {
            $table->index(['batch_id', 'status', 'updated_at'], 'broker_import_rows_allowance_index');
        });

        EnumCheck::add('broker_import_batches', 'stop_reason', ImportStopReason::class, nullable: true);
        EnumCheck::add('brokers', 'imports_paused_reason', ImportsPauseReason::class, nullable: true);
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `broker_import_batches` DROP CONSTRAINT `chk_broker_import_batches_stop_reason`');
            DB::statement('ALTER TABLE `brokers` DROP CONSTRAINT `chk_brokers_imports_paused_reason`');
        }

        Schema::table('broker_import_rows', function (Blueprint $table): void {
            $table->dropIndex('broker_import_rows_allowance_index');
        });

        Schema::table('brokers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('imports_paused_batch_id');
            $table->dropColumn('imports_paused_reason');
        });

        Schema::table('broker_import_batches', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['fix_sheet_path', 'zip_names', 'rows_skipped', 'rows_submitted', 'rows_approved', 'rows_rejected', 'import_started_at', 'stop_reason']);
        });
    }
};
