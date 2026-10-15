<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| KPI snapshots for the admin dashboard (PRD A02: "KPIs from nightly/hourly snapshot tables
| (`metric_snapshots`), never aggregated live; 'as of' timestamp shown"). One row per metric and
| IST day: the hourly run rewrites today's row, the run just after midnight closes yesterday's.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('metric', 60);
            $table->date('day');                     // IST calendar day the value belongs to
            $table->bigInteger('value');
            $table->dateTime('computed_at');         // the "as of" shown on the dashboard

            $table->unique(['metric', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};
