<?php

declare(strict_types=1);

namespace App\Jobs\Broker;

use App\Enums\AdminStatus;
use App\Models\AdminUser;
use App\Notifications\Admin\PayoutRunDue;
use App\Queries\Admin\PayoutsDue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * §11A B.23 "payout-run reminders", checked daily at 09:00 IST: while a run is due (PayoutsDue),
 * every ACTIVE admin holding `brokers.payout` is emailed — once per month, a re-run or a retry sends
 * nobody a second one (a cache marker per admin). The A02 alert keeps showing until the run exists.
 */
final class SendPayoutRunReminder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(PayoutsDue $due): void
    {
        $month = $due->month();

        if ($month === null) {
            return;
        }

        AdminUser::query()->where('status', AdminStatus::Active->value)->get()
            ->filter(fn (AdminUser $admin): bool => $admin->can('brokers.payout'))
            ->filter(fn (AdminUser $admin): bool => Cache::add('broker-payout-reminder:'.$month->format('Y-m').':'.$admin->id, true, now()->addDays(40)))
            ->each(fn (AdminUser $admin) => $admin->notify(new PayoutRunDue($month->format('F Y'))));
    }
}
