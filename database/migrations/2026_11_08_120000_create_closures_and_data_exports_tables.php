<?php

declare(strict_types=1);

use App\Enums\ClosureReason;
use App\Enums\DataExportStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P8.2c — M14 "Hide / delete profile" and "Download my data":
|  - profile_closures: one row each time a member closes their own account (reason, optional
|    feedback). restored_at is set when they restore it by signing in inside the recovery window.
|    Only an unrestored closure can be self-restored — an A03 (admin) delete has none.
|    The feedback is the member's own words: never shown to anyone else, cleared by the purge.
|  - data_exports: one "download my data" request. The zip lives on the private disk until
|    expires_at (24 h after it is ready); URLs use `code` (DEX…), never the id, and carry no token.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_closures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 30);
            $table->string('feedback', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();   // explicit default: MariaDB implicit ON UPDATE
            $table->timestamp('restored_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['reason', 'created_at']);
        });
        EnumCheck::add('profile_closures', 'reason', ClosureReason::class);

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 16)->unique();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        EnumCheck::add('data_exports', 'status', DataExportStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('profile_closures');
    }
};
