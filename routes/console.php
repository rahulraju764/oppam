<?php

declare(strict_types=1);

use App\Jobs\Members\PurgeDeletedMembers;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A03 two-stage deletion: anonymise members deleted longer ago than members.purge_after_days.
// 21:30 UTC = 03:00 IST, the quietest hour.
Schedule::job(new PurgeDeletedMembers)->dailyAt('21:30')->onOneServer()->withoutOverlapping();
