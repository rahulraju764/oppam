<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A12 alerts (P9.2b2): one row per alert — opened when CheckSystemHealth sees a threshold crossed,
| resolved once the condition has stayed clear for A15 `system.alert_clear_minutes`. At most ONE open
| alert per kind: `open_key` holds the kind while the alert is open and NULL once resolved, under a
| UNIQUE index (MySQL has no partial unique index; NULLs don't collide). `notified_at` = the one email
| was queued. Resolved alerts are kept 90 days (PruneSystemHealth).
| dateTime, not timestamp (TimestampColumnsTest: no MariaDB ON UPDATE).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 40);            // SystemAlertKind
            $table->string('open_key', 40)->nullable()->unique();
            $table->integer('value');              // the latest observed value, in the kind's unit
            $table->integer('threshold');
            $table->dateTime('opened_at');
            $table->dateTime('last_seen_at');      // last check that still saw it crossed
            $table->dateTime('clear_since')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('notified_at')->nullable();

            $table->index(['resolved_at', 'opened_at']);
            $table->index(['kind', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
