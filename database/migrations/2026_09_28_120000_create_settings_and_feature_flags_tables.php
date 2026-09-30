<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Admin-editable settings and feature flags (PRD A15). Keys are App\Enums\SettingKey / Flag;
| a key with no row falls back to its enum default. Every change is audited (audit_logs).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->foreignUlid('updated_by_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->boolean('is_enabled')->default(false);
            $table->foreignUlid('updated_by_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('settings');
    }
};
