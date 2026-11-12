<?php

declare(strict_types=1);

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A14 campaigns, part 2 (P8.3b1). A new migration — the 130000 one is already on phase-1.
 *  - campaigns: a public code (CMP…, used in URLs), the button link, the SMS DLT template id, who
 *    cancelled it, when its last batch ran (a stalled send is restarted); the first pass's counters
 *    go — the numbers are counted from campaign_recipients and outbound_messages.
 *  - campaign_recipients: the audience frozen at submit, one row per member, re-checked at send.
 *  - outbound_messages.opened_at: from the mail provider's signed "opened" events (no pixel).
 * The first pass only ever wrote test rows; any campaign it left is closed as CANCELLED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('code', 16)->nullable()->unique()->after('id');
            $table->string('action_url', 255)->nullable()->after('message');
            $table->string('action_text', 50)->nullable()->after('action_url');
            $table->string('dlt_template_id', 50)->nullable()->after('action_text');
            $table->foreignUlid('submitted_by')->nullable()->after('created_by')->constrained('admin_users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable()->after('submitted_by');
            $table->foreignUlid('cancelled_by')->nullable()->after('cancelled_at')->constrained('admin_users')->nullOnDelete();
            // One batch chain per campaign: only the job carrying the current token may run; a restart issues
            // a new token, so any older queued batch exits. next_batch_at = when the chain is due next (stall check).
            $table->char('batch_token', 26)->nullable()->after('started_at');
            $table->dateTime('next_batch_at')->nullable()->after('batch_token');
            $table->dropColumn(['sent_count', 'delivered_count', 'failed_count']);
        });

        foreach (DB::table('campaigns')->pluck('id') as $id) {
            DB::table('campaigns')->where('id', $id)->update(['code' => 'CMP'.Str::upper(Str::random(8))]);
        }
        // Every campaign is addressed by its code (URLs, links): required once the old rows have one.
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('code', 16)->nullable(false)->change();
        });
        DB::table('campaigns')->whereNotIn('status', ['DRAFT', 'COMPLETED', 'CANCELLED'])
            ->update(['status' => CampaignStatus::Cancelled->value, 'cancelled_at' => now()]);

        EnumCheck::add('campaigns', 'status', CampaignStatus::class);
        EnumCheck::add('campaigns', 'channel', CommsChannel::class);

        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default(CampaignRecipientStatus::Pending->value);
            $table->string('skip_reason', 12)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['campaign_id', 'user_id']);
            $table->index(['campaign_id', 'status', 'id']);
            $table->index('user_id');
        });
        EnumCheck::add('campaign_recipients', 'status', CampaignRecipientStatus::class);
        EnumCheck::add('campaign_recipients', 'skip_reason', CampaignSkipReason::class, nullable: true);

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->timestamp('opened_at')->nullable()->after('bounced_at');
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['campaign_id', 'status']);
            $table->dropColumn('opened_at');
        });

        Schema::dropIfExists('campaign_recipients');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `campaigns` DROP CONSTRAINT `chk_campaigns_status`');
            DB::statement('ALTER TABLE `campaigns` DROP CONSTRAINT `chk_campaigns_channel`');
        }

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'action_url', 'action_text', 'dlt_template_id', 'submitted_at', 'batch_token', 'next_batch_at']);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
        });
    }
};
