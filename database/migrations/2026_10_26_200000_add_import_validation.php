<?php

declare(strict_types=1);

use App\Enums\ImportBatchStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P7.6 — bulk upload validation (§11A B.9 steps 1–3, decisions 2026-10-26):
|  - batch status MAPPING (required columns not recognised: the bureau maps the headers);
|  - `headers`: the sheet's header row, for the mapping screen and errors.xlsx;
|  - `duplicate_of_batch_id`: this bureau uploaded the same file (sha256) before — a banner;
|  - `error_message`: why a batch FAILED, safe to show to the bureau (never cell contents).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_import_batches', function (Blueprint $table): void {
            $table->json('headers')->nullable()->after('column_map');
            $table->foreignUlid('duplicate_of_batch_id')->nullable()->after('file_sha256')->constrained('broker_import_batches')->nullOnDelete();
            $table->string('error_message', 300)->nullable()->after('status');
        });

        $this->replaceStatusCheck();
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('broker_import_batches')->where('status', 'MAPPING')->update(['status' => 'FAILED']);
            DB::statement('ALTER TABLE `broker_import_batches` DROP CONSTRAINT `chk_broker_import_batches_status`');
            DB::statement("ALTER TABLE `broker_import_batches` ADD CONSTRAINT `chk_broker_import_batches_status` CHECK (`status` IN ('UPLOADED', 'VALIDATING', 'READY', 'IMPORTING', 'COMPLETED', 'FAILED', 'CANCELLED'))");
        }

        Schema::table('broker_import_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicate_of_batch_id');
            $table->dropColumn(['headers', 'error_message']);
        });
    }

    private function replaceStatusCheck(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `broker_import_batches` DROP CONSTRAINT `chk_broker_import_batches_status`');
            EnumCheck::add('broker_import_batches', 'status', ImportBatchStatus::class);
        }
    }
};
