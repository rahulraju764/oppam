<?php

declare(strict_types=1);

namespace App\Jobs\Members;

use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Daily (routes/console.php): anonymise every member whose deletion is older than the restore
 * window (A03 two-stage deletion). Idempotent — each member is re-checked under lock by
 * PurgeDeletedMember, so a re-run or an overlapping run changes nothing twice.
 */
final class PurgeDeletedMembers implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PurgeDeletedMember $purge): void
    {
        User::onlyTrashed()
            ->where('role', UserRole::Member->value)
            ->whereNull('anonymised_at')
            ->where('deleted_at', '<=', now()->subDays(Settings::int(SettingKey::MembersPurgeAfterDays)))
            ->lazyById(100)
            ->each(function (User $member) use ($purge): void {
                try {
                    $purge->handle($member);
                } catch (Throwable $e) {
                    report($e);   // this member is retried tomorrow; the rest still run
                }
            });
    }
}
