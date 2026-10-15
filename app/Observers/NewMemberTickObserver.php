<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\Metric;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\Admin\AdminDashboardTick;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * A member account was created: tell the open admin dashboards today's signup count (PRD A02
 * "admin.dashboard ticks today's signups"). An observer on the User model (registered in
 * AppServiceProvider) so the registration code itself is untouched.
 *
 * Everything it does — the counter and the broadcast — runs AFTER the registration has committed
 * and is rescued: a cache, database or queue problem is reported, never thrown, so it can neither
 * fail nor delay a registration. The live number is a hint between two hourly snapshots; the
 * snapshot is the figure of record.
 *
 * Today's number is counted once per IST day and then kept in the cache (+1 per signup), so a
 * registration never costs a count over the users table. Seeders and other console work don't
 * tick (thousands of accounts would mean thousands of broadcasts).
 */
final class NewMemberTickObserver
{
    public function __construct(
        private readonly Cache $cache,
        private readonly Application $app,
    ) {}

    public function created(User $user): void
    {
        if ($user->role !== UserRole::Member || ($this->app->runningInConsole() && ! $this->app->runningUnitTests())) {
            return;
        }

        DB::afterCommit(function (): void {
            rescue(fn () => event(new AdminDashboardTick(Metric::NewMembers, $this->countedToday())), report: true);
        });
    }

    private function countedToday(): int
    {
        $day = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfDay();
        $key = 'admin:signups:'.$day->toDateString();

        // The first signup of the day (or after a cache flush) counts from the table; add() is
        // atomic, so two at once can't both seed it…
        if (! $this->cache->has($key)) {
            $count = $this->countSince($day);

            if ($this->cache->add($key, $count, 26 * 3600)) {
                return $count;
            }
        }

        // …every later one only adds itself.
        return (int) $this->cache->increment($key);
    }

    private function countSince(CarbonImmutable $day): int
    {
        return User::query()->where('role', UserRole::Member->value)->where('status', '!=', UserStatus::Deleted->value)
            ->where('created_at', '>=', $day->utc())->count();
    }
}
