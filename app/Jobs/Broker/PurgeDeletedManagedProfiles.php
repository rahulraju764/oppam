<?php

declare(strict_types=1);

namespace App\Jobs\Broker;

use App\Actions\Broker\ManagedProfiles\PurgeManagedProfile;
use App\Enums\ProfileOwnerType;
use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Daily (routes/console.php): purge every bureau-managed profile deleted longer ago than its
 * 30-day recovery (§11A B.6). Each profile is re-checked under lock by PurgeManagedProfile, so a
 * re-run or an overlapping run changes nothing twice; one failure doesn't stop the rest.
 */
final class PurgeDeletedManagedProfiles implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PurgeManagedProfile $purge): void
    {
        Profile::onlyTrashed()
            ->where('owner_type', ProfileOwnerType::BrokerManaged->value)
            ->whereNull('user_id')
            ->whereNotNull('client_phone')
            ->whereNull('legal_hold_at')
            ->where('deleted_at', '<=', now()->subDays((int) config('oppam.broker.managed_restore_days')))
            ->lazyById(100)
            ->each(function (Profile $profile) use ($purge): void {
                try {
                    $purge->handle($profile);
                } catch (Throwable $e) {
                    report($e);   // retried tomorrow; the rest still run
                }
            });
    }
}
