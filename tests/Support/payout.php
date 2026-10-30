<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\Payouts\ApprovePayoutRun;
use App\Actions\Admin\Brokers\Payouts\CreatePayoutRun;
use App\Actions\Admin\Brokers\Payouts\MarkPayoutPaid;
use App\Enums\ReferralStatus;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use App\Models\BrokerReferral;
use Carbon\CarbonImmutable;

/*
| P7.8 payout test helpers: EARNED ledger rows of a bureau, last month's period (IST) and the
| run → approve → pay steps through the real Actions.
*/

/** An EARNED commission of $paise for $broker, earned in the middle of last month (IST). */
function earnedCommission(Broker $broker, int $paise, ?CarbonImmutable $earnedAt = null): BrokerReferral
{
    return BrokerReferral::factory()->create([
        'broker_id' => $broker->id,
        'status' => ReferralStatus::Earned,
        'base_paise' => $paise * 10,
        'commission_bps' => 1000,
        'commission_paise' => $paise,
        'earned_at' => $earnedAt ?? lastMonth()[0]->addDays(10)->utc(),
    ]);
}

/** @return array{0: CarbonImmutable, 1: CarbonImmutable} last month's first and last day in IST */
function lastMonth(): array
{
    $start = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfMonth()->subMonth();

    return [$start, $start->endOfMonth()->startOfDay()];
}

function createRun(AdminUser $admin, ?string $override = null): BrokerPayoutRun
{
    [$start, $end] = lastMonth();

    return app(CreatePayoutRun::class)->handle($admin, $start->toDateString(), $end->toDateString(), $override);
}

/** Create, approve (one approver) and pay every payable line of last month's run. */
function paidRun(AdminUser $admin): BrokerPayoutRun
{
    $run = createRun($admin);
    app(ApprovePayoutRun::class)->handle($admin, $run);

    BrokerPayoutItem::query()->where('run_id', $run->id)->where('status', App\Enums\PayoutItemStatus::Pending)->with('broker')->get()
        ->each(fn (BrokerPayoutItem $item) => app(MarkPayoutPaid::class)->handle($admin, $run->refresh(), (string) $item->broker?->code, 'UTR'.strtoupper(Illuminate\Support\Str::random(10))));

    return $run->refresh();
}

function lineOf(BrokerPayoutRun $run, Broker $broker): BrokerPayoutItem
{
    return BrokerPayoutItem::query()->where('run_id', $run->id)->where('broker_id', $broker->id)->firstOrFail();
}
