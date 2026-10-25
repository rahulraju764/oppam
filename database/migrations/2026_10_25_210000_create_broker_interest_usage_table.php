<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7.4 — the bureau's monthly interest allowance for its managed clients (§11A B.6, R-M13-35,
| `brokers.interest_allowance_monthly`): one counter per bureau and IST month, consumed with a
| conditional update inside the interest's transaction (decisions 2026-10-25).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_interest_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('broker_id')->constrained('brokers')->cascadeOnDelete();
            $table->char('period', 7);                       // YYYY-MM, Asia/Kolkata
            $table->unsignedSmallInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['broker_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_interest_usage');
    }
};
