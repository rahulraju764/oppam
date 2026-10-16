<?php

declare(strict_types=1);

use App\Jobs\Chat\PruneChatHistory;
use App\Jobs\Chat\SendChatDigests;
use App\Jobs\Engagement\ExpireInterests;
use App\Jobs\Matching\GenerateDailyMatches;
use App\Jobs\Members\PurgeDeletedMembers;
use App\Jobs\Notifications\PruneOldNotifications;
use App\Jobs\Search\SendSavedSearchAlerts;
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

// R-M08-4: in-app notifications older than 180 days. 21:45 UTC = 03:15 IST.
Schedule::job(new PruneOldNotifications)->dailyAt('21:45')->onOneServer()->withoutOverlapping();

// Chat (M07, P4.2): the offline message digest every 5 minutes; retention (R-M07-6) nightly.
Schedule::job(new SendChatDigests)->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::job(new PruneChatHistory)->dailyAt('21:50')->onOneServer()->withoutOverlapping();
