<?php

declare(strict_types=1);

use App\Jobs\Billing\ExpireSubscriptions;
use App\Jobs\Billing\ExpireUnpaidOrders;
use App\Jobs\Billing\SendPlanExpiryReminders;
use App\Jobs\Broker\PurgeDeletedManagedProfiles;
use App\Jobs\Broker\PurgeImportFiles;
use App\Jobs\Broker\SendFollowUpReminders;
use App\Jobs\Chat\PruneChatHistory;
use App\Jobs\Chat\SendChatDigests;
use App\Jobs\Engagement\DetectEngagementSignals;
use App\Jobs\Engagement\ExpireInterests;
use App\Jobs\Matching\GenerateDailyMatches;
use App\Jobs\Members\PurgeDeletedMembers;
use App\Jobs\Notifications\PruneOldNotifications;
use App\Jobs\Search\SendSavedSearchAlerts;
use App\Jobs\Verification\PurgeExpiredVerificationDocuments;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A03 two-stage deletion: anonymise members deleted longer ago than members.purge_after_days.
// 21:30 UTC = 03:00 IST, the quietest hour.
Schedule::job(new PurgeDeletedMembers)->dailyAt('21:30')->onOneServer()->withoutOverlapping();

// F06: Daily matches generated at 05:00 IST for all active members (PRD §12 F06).
Schedule::job(new GenerateDailyMatches)->timezone('Asia/Kolkata')->dailyAt('05:00')->onOneServer()->withoutOverlapping();

// M04: Saved search daily alerts sent at 08:00 IST.
Schedule::job(new SendSavedSearchAlerts)->timezone('Asia/Kolkata')->dailyAt('08:00')->onOneServer()->withoutOverlapping();

// A02: dashboard KPI snapshots — today's row every hour, yesterday closed just after midnight IST.
// The overlap lock lapses after 10 minutes: a run that was killed must not stop the next hours.
Schedule::command('metrics:snapshot')->hourlyAt(5)->onOneServer()->withoutOverlapping(10);
Schedule::command('metrics:snapshot --yesterday')->timezone('Asia/Kolkata')->dailyAt('00:10')->onOneServer()->withoutOverlapping(10);

// F02: pending interests past their 30 days become EXPIRED, every hour.
Schedule::job(new ExpireInterests)->hourly()->onOneServer()->withoutOverlapping();

// A13 abuse monitors that look back over time: interest spam, new accounts messaging many members.
Schedule::job(new DetectEngagementSignals)->hourlyAt(20)->onOneServer()->withoutOverlapping();

// R-M08-4: in-app notifications older than 180 days. 21:45 UTC = 03:15 IST.
Schedule::job(new PruneOldNotifications)->dailyAt('21:45')->onOneServer()->withoutOverlapping();

// Chat (M07, P4.2): the offline message digest every 5 minutes; retention (R-M07-6) nightly.
Schedule::job(new SendChatDigests)->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::job(new PruneChatHistory)->dailyAt('21:50')->onOneServer()->withoutOverlapping();

// M10: unpaid checkout orders past their expiry (a late capture still activates them).
Schedule::job(new ExpireUnpaidOrders)->hourlyAt(40)->onOneServer()->withoutOverlapping();

// M10 expiry (P5.2): ended plans → Free limits + flags + plan_expired; reminders 7 days / 1 day before, 10:00 IST.
Schedule::job(new ExpireSubscriptions)->everyTenMinutes()->onOneServer()->withoutOverlapping();
Schedule::job(new SendPlanExpiryReminders)->timezone('Asia/Kolkata')->dailyAt('10:00')->onOneServer()->withoutOverlapping();

// M09 / A05 (P6.1): ID files deleted (and the deletion verified) 90 days after the decision, 03:30 IST.
Schedule::job(new PurgeExpiredVerificationDocuments)->timezone('Asia/Kolkata')->dailyAt('03:30')->onOneServer()->withoutOverlapping();

// §11A B.15 FollowUpDue: bureau client follow-ups due today, at 08:30 IST (P7.3).
Schedule::job(new SendFollowUpReminders)->timezone('Asia/Kolkata')->dailyAt('08:30')->onOneServer()->withoutOverlapping();

// §11A B.6: managed profiles deleted longer than the 30-day recovery are purged (21:40 UTC = 03:10 IST).
Schedule::job(new PurgeDeletedManagedProfiles)->dailyAt('21:40')->onOneServer()->withoutOverlapping();

// §11A B.9 / B.23: bulk-import source files purged after broker.import_file_retention_days (30), 03:45 IST (P7.7).
Schedule::job(new PurgeImportFiles)->timezone('Asia/Kolkata')->dailyAt('03:45')->onOneServer()->withoutOverlapping();
