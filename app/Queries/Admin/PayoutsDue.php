<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Enums\ReferralStatus;
use App\Enums\SettingKey;
use App\Models\Broker;
use App\Models\BrokerPayoutRun;
use App\Models\BrokerReferral;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;

/**
 * §11A B.23 "payout-run reminders" (decisions 2026-10-31): the month a payout run is overdue for —
 * from `broker.payout_reminder_day` (IST), last month when no run covers its last day yet and a
 * payable bureau has EARNED commission from then or before that isn't in a line. Shared by
 * SendPayoutRunReminder and the A02 alert.
 */
final class PayoutsDue
{
    public function month(): ?CarbonImmutable
    {
        $today = CarbonImmutable::now((string) config('oppam.display_timezone'));
        if ($today->day < Settings::int(SettingKey::BrokerPayoutReminderDay)) {
            return null;
        }

        $lastDay = $today->startOfMonth()->subDay();

        $covered = BrokerPayoutRun::query()->where('period_end', '>=', $lastDay->toDateString())->exists();
        if ($covered) {
            return null;
        }

        $unpaid = BrokerReferral::query()->where('status', ReferralStatus::Earned)->whereNull('payout_item_id')
            ->where('earned_at', '<=', $lastDay->endOfDay()->utc())
            ->whereIn('broker_id', Broker::query()->payable()->select('id'))
            ->exists();

        return $unpaid ? $lastDay->startOfMonth() : null;
    }
}
