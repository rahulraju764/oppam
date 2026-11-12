<?php

declare(strict_types=1);

use App\Enums\CommsChannel;
use App\Enums\JourneyKey;
use App\Enums\JourneySendStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * A14 automated journeys (P8.3b2). The first pass kept one `trigger_days` and a template id per
 * journey and sent nothing. Now:
 *  - automated_journeys: day offsets (json list, e.g. [1, 3, 7]) and one channel; the copy comes
 *    from the A14 templates of the journey's notification event, so no template id. The four
 *    journeys are seeded here (never on a page load), all OFF until an admin switches one on.
 *    The first pass's `plan_expiry` row goes: plan-expiry reminders stay the P5.2 job.
 *  - journey_sends: the ledger. A step is never repeated for the same subject (unique), and at most
 *    one journey message a member a day (the job checks SENT rows for the IST day).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('automated_journeys')->delete();   // first-pass rows: never sent anything

        Schema::table('automated_journeys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn('trigger_days');
        });
        Schema::table('automated_journeys', function (Blueprint $table): void {
            $table->json('offsets')->after('is_active');
            $table->boolean('is_active')->default(false)->change();
        });
        EnumCheck::add('automated_journeys', 'key', JourneyKey::class);
        EnumCheck::add('automated_journeys', 'channel', CommsChannel::class);

        $seed = [
            JourneyKey::PendingInterest->value => CommsChannel::InApp,
            JourneyKey::WizardIncomplete->value => CommsChannel::Email,
            JourneyKey::InactiveMatches->value => CommsChannel::Email,
            JourneyKey::WinBack->value => CommsChannel::Email,
        ];
        foreach ($seed as $key => $channel) {
            $journey = JourneyKey::from($key);
            DB::table('automated_journeys')->insert([
                'id' => (string) Str::ulid(),
                'key' => $key,
                'name' => $journey->label(),
                'description' => $journey->description(),
                'is_active' => false,
                'offsets' => json_encode($journey->defaultOffsets()),
                'channel' => $channel->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('journey_sends', function (Blueprint $table): void {
            $table->id();
            $table->string('journey_key', 30);
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            // What the step was about: the profile (wizard), interest + its send time, the ended
            // subscription, the start of an inactive spell — so a new subject gets its own reminder.
            $table->string('subject_ref', 64);
            $table->date('sent_on');   // IST day
            $table->string('status', 24);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['journey_key', 'user_id', 'step', 'subject_ref']);
            $table->index(['user_id', 'sent_on', 'status']);
            $table->index(['created_at', 'journey_key', 'status']);   // the screen's 30-day numbers and the 400-day prune
        });
        EnumCheck::add('journey_sends', 'journey_key', JourneyKey::class);
        EnumCheck::add('journey_sends', 'status', JourneySendStatus::class);

        // The daily audiences: drafts by registration day, ACTIVE profiles by last-active day.
        // (interests.sent_at and subscriptions (status, ends_at) are already indexed.)
        Schema::table('profiles', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'profiles_status_created_at_index');
            $table->index(['status', 'last_active_at'], 'profiles_status_last_active_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_status_created_at_index');
            $table->dropIndex('profiles_status_last_active_at_index');
        });
        Schema::dropIfExists('journey_sends');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `automated_journeys` DROP CONSTRAINT `chk_automated_journeys_key`');
            DB::statement('ALTER TABLE `automated_journeys` DROP CONSTRAINT `chk_automated_journeys_channel`');
        }
        DB::table('automated_journeys')->delete();

        Schema::table('automated_journeys', function (Blueprint $table): void {
            $table->dropColumn('offsets');
            $table->boolean('is_active')->default(true)->change();
        });
        Schema::table('automated_journeys', function (Blueprint $table): void {
            $table->unsignedInteger('trigger_days')->default(3)->after('is_active');
            $table->foreignUlid('template_id')->nullable()->constrained('communication_templates')->nullOnDelete();
        });
    }
};
