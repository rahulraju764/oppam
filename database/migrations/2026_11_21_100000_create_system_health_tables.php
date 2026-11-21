<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A12 system health (P9.2b1) — what the platform records about itself:
|  - scheduled_task_runs: one row per scheduler run of a task (RecordScheduledTaskRun), so the panel
|    shows last run / status / duration and finds MISSED runs. Kept 30 days (PruneSystemHealth).
|  - webhook_receipts: one row per inbound webhook request (RecordWebhookReceipt) — provider, outcome
|    (accepted / signature or body rejected / error), status, duration. Never the body, never the IP.
|    Kept 30 days.
|  - health_samples: per-minute samples (Reverb connections, broadcast queue lag) for trends, the
|    "last restart (detected)" and, in P9.2b2, the drop / lag alerts. Kept 7 days.
|  - payment_webhook_events (processed_at, created_at): the "not processed" list.
| dateTime, not timestamp (TimestampColumnsTest: no MariaDB ON UPDATE).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('task', 120);
            $table->string('expression', 60);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 10);   // ScheduledRunStatus
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 120)->nullable();   // exception class only — never its message

            $table->index(['task', 'started_at']);
            $table->index('started_at');
        });

        Schema::create('webhook_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 20);   // WebhookProvider
            $table->string('outcome', 20);    // WebhookOutcome
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('duration_ms');
            $table->dateTime('received_at');

            $table->index(['provider', 'received_at']);
            $table->index('received_at');
        });

        Schema::create('health_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('metric', 40);   // HealthMetric
            $table->bigInteger('value')->nullable();   // null = could not be measured (e.g. Reverb unreachable)
            $table->dateTime('sampled_at');

            $table->index(['metric', 'sampled_at']);
            $table->index('sampled_at');
        });

        // The health panel's "payment events not processed" list (processed_at IS NULL, newest first).
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->index(['processed_at', 'created_at'], 'payment_webhook_events_processed_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table): void {
            $table->dropIndex('payment_webhook_events_processed_created_index');
        });

        Schema::dropIfExists('health_samples');
        Schema::dropIfExists('webhook_receipts');
        Schema::dropIfExists('scheduled_task_runs');
    }
};
