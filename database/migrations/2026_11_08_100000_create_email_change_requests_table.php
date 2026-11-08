<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P8.2b — proving an email address (PRD M14 Account; owner decisions 2026-11-08): one row per link
| sent. The link carries a random token; only its SHA-256 is stored. Single use (consumed_at),
| expires after `oppam.auth.email_verify_hours`. The account's email changes only when the link
| is opened — until then the old address stays in use.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_change_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->useCurrent();   // explicit default: see docs/decisions.md (MariaDB implicit ON UPDATE)
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();   // no bare timestamp: MariaDB would add ON UPDATE
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_requests');
    }
};
