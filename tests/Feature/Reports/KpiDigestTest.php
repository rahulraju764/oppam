<?php

declare(strict_types=1);

use App\Actions\Admin\Settings\UpdateSettings;
use App\Enums\AdminStatus;
use App\Jobs\Reports\SendKpiDigest;
use App\Models\ReportSnapshot;
use App\Notifications\Admin\WeeklyKpiDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
| P9.1b — A09 weekly KPI email (owner 2026-11-13): to every ACTIVE admin with reports.view, on the
| A15 digest day (default Monday) at 08:00 IST, once per admin per week, off when disabled; last
| Monday–Sunday vs the week before; numbers only (no member or admin names / contacts).
*/

beforeEach(function (): void {
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
});

function runDigest(): void
{
    app()->call([new SendKpiDigest, 'handle']);
}

it('A09 digest: on Monday, to every active admin who can view reports — not to others, not to a suspended admin; once a week', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 08:00', 'Asia/Kolkata'));   // a Monday
    $super = adminWithRole('super_admin');
    $finance = adminWithRole('finance');
    $moderator = adminWithRole('moderator');                        // no reports.view
    $suspended = adminWithRole('finance');
    $suspended->forceFill(['status' => AdminStatus::Suspended])->save();

    runDigest();
    runDigest();   // a second run the same week sends nothing more

    Notification::assertSentToTimes($super, WeeklyKpiDigest::class, 1);
    Notification::assertSentToTimes($finance, WeeklyKpiDigest::class, 1);
    Notification::assertNotSentTo($moderator, WeeklyKpiDigest::class);
    Notification::assertNotSentTo($suspended, WeeklyKpiDigest::class);
});

it('A09 digest: last Monday–Sunday against the week before, numbers only', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 08:00', 'Asia/Kolkata'));
    $admin = adminWithRole('super_admin');
    foreach (['2026-11-16' => 6, '2026-11-22' => 4, '2026-11-10' => 5] as $day => $n) {
        ReportSnapshot::query()->insert(['metric' => 'signups', 'day' => $day, 'dimension' => '', 'value' => $n, 'computed_at' => now()]);
    }

    runDigest();

    Notification::assertSentTo($admin, WeeklyKpiDigest::class, function (WeeklyKpiDigest $n) use ($admin): bool {
        $signups = collect($n->lines)->firstWhere('label', 'Signups');
        $mail = $n->toMail($admin);
        $text = json_encode($mail->introLines);

        return $n->week === '16 Nov – 22 Nov 2026'
            && $signups['value'] === '10' && $signups['delta'] === '+100%'   // 10 against 5
            && count($n->lines) === 10
            && ! str_contains((string) $text, '@')                          // no email addresses
            && str_contains($mail->actionUrl, '/reports');
    });
});

it('A09 digest: another day sends nothing; the A15 day and on/off apply without a deploy', function (): void {
    $admin = adminWithRole('super_admin');
    $this->travelTo(CarbonImmutable::parse('2026-11-24 08:00', 'Asia/Kolkata'));   // Tuesday
    runDigest();
    Notification::assertNothingSent();

    app(UpdateSettings::class)->handle($admin, ['reports.digest_day' => 2], 'Tuesday works better');
    runDigest();
    Notification::assertSentToTimes($admin, WeeklyKpiDigest::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00', 'Asia/Kolkata'));   // next Tuesday
    app(UpdateSettings::class)->handle($admin, ['reports.digest_enabled' => false], 'Pause it');
    runDigest();
    Notification::assertSentToTimes($admin, WeeklyKpiDigest::class, 1);
});

it('A09 digest: checked daily at 08:00 IST', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, 'SendKpiDigest'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * *')
        ->and($event->timezone)->toBe('Asia/Kolkata');
});
