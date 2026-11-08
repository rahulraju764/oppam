<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 60)->unique();
            $table->string('name', 100);
            $table->string('channel', 20); // email, sms, in_app
            $table->string('subject', 200)->nullable();
            $table->string('dlt_template_id', 50)->nullable();
            $table->text('body');
            $table->json('variables')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['channel', 'key']);
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title', 150);
            $table->text('body');
            $table->string('action_url', 255)->nullable();
            $table->string('action_text', 50)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('target_plan', 20)->nullable();
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('is_active')->default(true);
            $table->dateTime('broadcast_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('channel', 20); // email, sms, in_app
            $table->json('segment_filters');
            $table->foreignUlid('template_id')->nullable()->constrained('communication_templates')->nullOnDelete();
            $table->string('subject', 200)->nullable();
            $table->text('message');
            $table->string('status', 30)->default('DRAFT'); // DRAFT, PENDING_APPROVAL, APPROVED, SCHEDULED, SENDING, COMPLETED, CANCELLED
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedInteger('throttle_per_minute')->default(60);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignUlid('created_by')->constrained('admin_users')->cascadeOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('automated_journeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('trigger_days')->default(3);
            $table->string('channel', 20)->default('email');
            $table->foreignUlid('template_id')->nullable()->constrained('communication_templates')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20); // email, sms, in_app
            $table->string('recipient', 100);
            $table->string('event', 60)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('content')->nullable();
            $table->string('status', 20)->default('SENT'); // SENT, DELIVERED, FAILED, BOUNCED
            $table->string('provider_id', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignUlid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->dateTime('sent_at');
            $table->timestamps();

            $table->index(['channel', 'status', 'created_at']);
            $table->index(['recipient', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('automated_journeys');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('communication_templates');
    }
};
