<?php

declare(strict_types=1);

use App\Enums\AnnouncementAudience;
use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A14 template manager (P8.3a). A template is the copy of ONE notification event on ONE channel
        // (`key` = "{event}.{channel}", e.g. "profile_approved.email"), or a reusable campaign text
        // (event "campaign", key chosen by the admin). Its text lives in append-only versions; the
        // template points at the live one. No row = the code's own copy is used.
        Schema::create('communication_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 80)->unique();
            $table->string('event', 40);
            $table->string('channel', 20);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();

            $table->index(['event', 'channel']);
        });
        EnumCheck::add('communication_templates', 'channel', CommsChannel::class);

        Schema::create('communication_template_versions', function (Blueprint $table): void {   // append-only
            $table->ulid('id')->primary();
            $table->foreignUlid('template_id')->constrained('communication_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('subject', 200)->nullable();     // email only
            $table->text('body');                           // Markdown (email) or plain text (SMS / in-app)
            $table->string('dlt_template_id', 50)->nullable();   // SMS only
            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();   // explicit default: MariaDB implicit ON UPDATE

            $table->unique(['template_id', 'version']);
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 16)->unique();           // ANN… — used in URLs and live payloads, never the id
            $table->string('title', 150);
            $table->string('body', 500);
            $table->string('action_url', 255)->nullable();
            $table->string('action_text', 50)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('audience', 20)->default('ALL');
            $table->boolean('is_dismissible')->default(true);
            $table->timestamp('ended_at')->nullable();      // ended early by an admin
            $table->timestamp('broadcast_at')->nullable();  // the live signal went out (once)
            $table->timestamp('end_signalled_at')->nullable();   // open banners were told it ended by its window (once)
            $table->foreignUlid('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ended_at', 'starts_at', 'ends_at']);
            $table->index(['broadcast_at', 'starts_at']);
        });
        EnumCheck::add('announcements', 'audience', AnnouncementAudience::class);

        Schema::create('announcement_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['announcement_id', 'user_id']);
            $table->index('user_id');
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

        // A14 delivery log (P8.3a): one row per outbound email / SMS. Never the message text, never the
        // full number or address (masked); `payload` (encrypted) holds a resendable member
        // notification only — never one carrying a link token or code. Pruned after 180 days.
        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('recipient_masked', 60);
            $table->string('event', 60)->nullable();
            $table->string('notification_type', 120)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('status', 20)->default('SENT');
            $table->string('provider_id', 100)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->text('payload')->nullable();
            $table->foreignUlid('resent_from_id')->nullable()->constrained('outbound_messages')->nullOnDelete();
            $table->timestamp('resent_at')->nullable();      // this failed row was sent again (once)
            $table->foreignUlid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamps();

            $table->index('provider_id');
            $table->index(['status', 'created_at']);
            $table->index(['channel', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
        EnumCheck::add('outbound_messages', 'channel', CommsChannel::class);
        EnumCheck::add('outbound_messages', 'status', OutboundStatus::class);

        // Mail-provider webhook replay guard (Mailgun token, 15-minute window).
        Schema::create('webhook_replay_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->char('token_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'token_hash']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_replay_tokens');
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('automated_journeys');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('communication_template_versions');
        Schema::dropIfExists('communication_templates');
    }
};
