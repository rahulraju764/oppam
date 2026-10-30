<?php

declare(strict_types=1);

use App\Enums\ConsentDisputeStatus;
use App\Enums\DisputeSource;
use App\Enums\ProfileStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7.8b — A07 oversight (§11A B.12, R-A07-10; decisions 2026-10-29):
|  - consent disputes: a client's "I never agreed to this profile", recorded by an admin, with its
|    linked A10 case and outcome (DSP1001 public code). Upheld disputes in the window count towards
|    the creation pause (R-A07-10);
|  - brokers: the creation pause (create + import refused, everything else keeps working) and why.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_consent_disputes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 12)->unique();                                 // DSP1001 — URLs and audit
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->foreignUlid('profile_id')->constrained('profiles');
            $table->foreignUlid('abuse_case_id')->nullable()->constrained('abuse_cases')->nullOnDelete();
            $table->string('source', 16);                                         // DisputeSource
            $table->string('note', 500);
            $table->string('status', 10)->default('OPEN');                        // ConsentDisputeStatus
            $table->string('profile_status_before', 16);                          // restored when dismissed
            $table->foreignUlid('recorded_by_admin_id')->constrained('admin_users');
            $table->foreignUlid('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('outcome_note', 500)->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'status', 'decided_at']);                 // R-A07-10 upheld-in-window count
            $table->index(['status', 'created_at']);                              // the A07 list
            $table->index(['profile_id', 'status']);
        });
        EnumCheck::add('broker_consent_disputes', 'status', ConsentDisputeStatus::class);
        EnumCheck::add('broker_consent_disputes', 'source', DisputeSource::class);
        EnumCheck::add('broker_consent_disputes', 'profile_status_before', ProfileStatus::class);

        Schema::table('brokers', function (Blueprint $table): void {
            $table->timestamp('creation_paused_at')->nullable()->after('imports_paused_batch_id');
            $table->string('creation_paused_reason', 300)->nullable()->after('creation_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('brokers', function (Blueprint $table): void {
            $table->dropColumn(['creation_paused_at', 'creation_paused_reason']);
        });

        Schema::dropIfExists('broker_consent_disputes');
    }
};
