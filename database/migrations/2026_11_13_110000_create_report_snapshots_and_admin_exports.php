<?php

declare(strict_types=1);

use App\Enums\AdminExportStatus;
use App\Enums\ExportFormat;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P9.1a (A09 reports):
 *  - report_snapshots: one number per report metric, IST day and dimension ('' = the total;
 *    "gender:FEMALE", "district:12", "source:BROKER", "tier:GOLD", "filter:religion" …), written
 *    nightly by reports:snapshot. Only numbers that add up across days are stored here; ratios
 *    are computed from their stored parts so any range and its comparison are exact.
 *  - admin_exports: a large report export built in the background (A09: "large exports queued &
 *    emailed"), private disk, the admin's own, 24 hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('metric', 60);
            $table->date('day');
            $table->string('dimension', 60)->default('');
            $table->bigInteger('value');
            $table->dateTime('computed_at');   // dateTime: MariaDB gives a bare timestamp ON UPDATE CURRENT_TIMESTAMP
            $table->unique(['metric', 'day', 'dimension']);
            $table->index(['day', 'metric']);
        });

        Schema::create('admin_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 16)->unique();
            $table->foreignUlid('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('report', 30);
            $table->json('params');
            $table->string('format', 8);
            $table->string('status', 12);
            $table->string('path', 255)->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['admin_user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        EnumCheck::add('admin_exports', 'format', ExportFormat::class);
        EnumCheck::add('admin_exports', 'status', AdminExportStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_exports');
        Schema::dropIfExists('report_snapshots');
    }
};
