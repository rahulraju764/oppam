<?php

declare(strict_types=1);

use App\Enums\AdminStatus;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\SettingKey;
use App\Jobs\Broker\SendPayoutRunReminder;
use App\Models\Broker;
use App\Models\Setting;
use App\Notifications\Admin\PayoutRunDue;
use App\Queries\Admin\DashboardAlerts;
use App\Queries\Admin\PayoutsDue;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
| §11A B.23 payout-run reminders (decisions 2026-10-31): due while a payable bureau has unpaid EARNED
| commission from last month or before and no run covers last month's last day; emailed once a month
| to active `brokers.payout` admins; the A02 alert stays until the run exists.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
    $this->travelTo(now('Asia/Kolkata')->setDate(2026, 11, 5)->setTime(9, 0));
    $this->finance = adminWithRole('finance');
    $this->broker = Broker::factory()->create();
});

function runReminder(): void
{
    (new SendPayoutRunReminder)->handle(app(PayoutsDue::class));
}

it('B.23: unpaid commission and no run → every brokers.payout admin is emailed, naming the month only', function (): void {
    earnedCommission($this->broker, 60_000);
    $support = adminWithRole('support');

    runReminder();

    Notification::assertSentTo($this->finance, PayoutRunDue::class, function (PayoutRunDue $n) use ($support): bool {
        $mail = $n->toMail($support);
        $text = $mail->subject.' '.implode(' ', $mail->introLines);

        return $n->month === 'October 2026' && str_contains($text, 'October 2026') && ! str_contains($text, $this->broker->code) && ! str_contains($text, '600');
    });
    Notification::assertNotSentTo($support, PayoutRunDue::class);
});

it('B.23: a suspended admin holding the permission gets nothing', function (): void {
    earnedCommission($this->broker, 60_000);
    $this->finance->forceFill(['status' => AdminStatus::Suspended])->save();

    runReminder();

    Notification::assertNotSentTo($this->finance, PayoutRunDue::class);
});

it('B.23: a re-run the same month sends nothing twice; the next month’s run is reminded again', function (): void {
    earnedCommission($this->broker, 60_000);

    runReminder();
    runReminder();
    Notification::assertSentToTimes($this->finance, PayoutRunDue::class, 1);

    $this->travelTo(now()->addMonth());
    runReminder();
    Notification::assertSentToTimes($this->finance, PayoutRunDue::class, 2);
});

it('B.23: nothing is due when a run covers last month — of any status', function (): void {
    earnedCommission($this->broker, 60_000);
    createRun($this->finance);
    earnedCommission($this->broker, 70_000);

    runReminder();

    Notification::assertNothingSent();
});

it('B.23: nothing is due without unpaid commission from last month or before', function (): void {
    runReminder();
    Notification::assertNothingSent();

    earnedCommission($this->broker, 60_000, now()->toImmutable());                     // earned this month: next month's run
    runReminder();
    Notification::assertNothingSent();
});

it('R-A07-2: commission of a bureau that can’t be paid doesn’t make a run due', function (array $state): void {
    $this->broker->forceFill($state)->save();
    earnedCommission($this->broker, 60_000);

    runReminder();

    Notification::assertNothingSent();
})->with([
    'suspended' => [['status' => BrokerStatus::Suspended]],
    'KYC revoked' => [['kyc_status' => BrokerKycStatus::Revoked]],
    'payouts held' => [['payouts_held_at' => '2026-11-01 00:00:00', 'payouts_hold_reason' => 'Fraud signal']],
]);

it('B.23: nothing is due before broker.payout_reminder_day (IST), and it starts on that day', function (): void {
    (new Setting)->forceFill(['key' => SettingKey::BrokerPayoutReminderDay->value, 'value' => 10])->save();
    Settings::flush();
    earnedCommission($this->broker, 60_000);

    runReminder();
    Notification::assertNothingSent();

    $this->travelTo(now()->addDays(5));
    runReminder();
    Notification::assertSentTo($this->finance, PayoutRunDue::class);
});

it('B.23: the month follows IST at the month boundary', function (): void {
    $this->travelTo(now('Asia/Kolkata')->setDate(2026, 12, 1)->setTime(0, 10));        // still 30 Nov in UTC
    earnedCommission($this->broker, 60_000, now()->subMonth()->toImmutable());

    expect(app(PayoutsDue::class)->month()?->format('Y-m'))->toBe('2026-11');
});

it('A02: the alert shows for brokers.payout only, until the run exists', function (): void {
    earnedCommission($this->broker, 60_000);
    $alerts = fn (bool $payouts): string => json_encode(app(DashboardAlerts::class)->for([], system: false, payouts: $payouts));

    expect($alerts(true))->toContain('payout run due')->toContain('October 2026')
        ->and($alerts(false))->not->toContain('payout run due');

    createRun($this->finance);
    expect($alerts(true))->not->toContain('payout run due');
});

it('B.23: scheduled daily at 09:00 IST', function (): void {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, 'SendPayoutRunReminder'));

    expect($event?->expression)->toBe('0 9 * * *')->and($event?->timezone)->toBe('Asia/Kolkata');
});
