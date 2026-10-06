<?php

declare(strict_types=1);

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

// R-M08-4: in-app notifications older than 180 days. 21:45 UTC = 03:15 IST.
Schedule::job(new PruneOldNotifications)->dailyAt('21:45')->onOneServer()->withoutOverlapping();
