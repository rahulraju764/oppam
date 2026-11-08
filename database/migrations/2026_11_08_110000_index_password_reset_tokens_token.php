<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P8.2b review — the email-link reset (ResetPasswordByEmail) looks a token up by its SHA-256 under
| a row lock; without an index InnoDB scans and locks the whole table.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropIndex(['token']);
        });
    }
};
