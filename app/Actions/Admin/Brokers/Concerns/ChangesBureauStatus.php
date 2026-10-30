<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Concerns;

use App\Enums\BrokerStatus;
use App\Enums\BureauStaffStatus;
use App\Events\Account\ForceLogout;
use App\Events\Broker\BrokerSuspended;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\User;
use App\Notifications\Broker\BureauStatusChanged;
use App\Services\Audit\AuditLogger;
use App\Support\Realtime\LiveSignal;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moving a bureau between ACTIVE / SUSPENDED / DEACTIVATED from A07 (§11A B.3, R-A07-8, B.21
 * "deactivating a bureau blocks Owner and staff at once and logs staff out live"). In one
 * transaction with the bureau row locked: the status changes, and when it stops being ACTIVE every
 * login of the bureau — Owner and staff, whatever their own status — has its sessions ended
 * (session epoch + 1, remember token rotated); after the commit each login gets ForceLogout and
 * the bureau's channels BrokerSuspended. broker.active refuses the bureau on the very next request
 * either way. Staff rows are left as they are, so a reactivation restores the same team. The
 * bureau's working logins are notified (email + in-app, never the admin's reason).
 */
trait ChangesBureauStatus
{
    /**
     * @param  Closure(Broker): void  $guard  throws when the bureau's current status doesn't allow the change
     */
    private function changeStatus(AuditLogger $audit, AdminUser $admin, Broker $broker, BrokerStatus $to, string $reason, string $action, Closure $guard): Broker
    {
        $logins = DB::transaction(function () use ($audit, $admin, $broker, $to, $reason, $action, $guard): Collection {
            $locked = Broker::query()->whereKey($broker->id)->lockForUpdate()->firstOrFail();
            $guard($locked);

            $before = ['status' => $locked->status->value];
            $locked->forceFill(['status' => $to, 'status_reason' => $to === BrokerStatus::Active ? null : $reason])->save();

            $logins = User::query()->whereIn('id', BrokerStaff::query()->where('broker_id', $locked->id)->select('user_id'))
                ->lockForUpdate()->get();

            if ($to !== BrokerStatus::Active) {
                foreach ($logins as $login) {
                    $login->forceFill(['session_epoch' => $login->session_epoch + 1, 'remember_token' => Str::random(60)])->save();
                    LiveSignal::afterCommit(new ForceLogout((string) $login->id));
                }
                LiveSignal::afterCommit(new BrokerSuspended($locked->id, $to->value));
            }

            $audit->record($action, $locked, $before, ['status' => $to->value, 'logins_signed_out' => $to === BrokerStatus::Active ? 0 : $logins->count()],
                reason: $reason, actor: $admin, subjectLabel: $locked->code);

            $broker->setRawAttributes($locked->getAttributes(), true);

            // Told: the bureau's working logins (Owner and ACTIVE staff), not ones it switched off earlier.
            $working = BrokerStaff::query()->where('broker_id', $locked->id)->where('status', BureauStaffStatus::Active)->pluck('user_id')->all();

            return $logins->whereIn('id', $working)->values();
        });

        $notification = new BureauStatusChanged($to);
        $logins->each(fn (User $login) => $login->notify($notification));

        return $broker;
    }
}
