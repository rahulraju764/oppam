<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\Signals\RecordFraudSignals;
use App\Actions\Admin\Brokers\Signals\ResolveFraudSignal;
use App\Actions\Admin\Brokers\Signals\VoidAllReferrals;
use App\Domain\Broker\FraudSignals;
use App\Domain\Broker\Import\ImportBatchSignals;
use App\Domain\Broker\SignupFingerprints;
use App\Enums\BrokerSignal;
use App\Enums\BureauRole;
use App\Enums\ConsentDisputeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Enums\SettingKey;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Jobs\Broker\ScanBrokerFraudSignals;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\BrokerFraudSignal;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\BrokerProfileExport;
use App\Models\BrokerReferral;
use App\Models\LoginEvent;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Notifications\Broker\BureauSignalRaised;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;

/*
| P7.8c — fraud & quality signals (PRD §11A B.12 signal table; owner 2026-10-29: capture the data;
| 2026-10-30: the three money signals hold payouts). One test per detector (present / absent at
| its threshold), the response (open once, hold, Owner told), resolve, void all, and the scan.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
    $this->admin = adminWithRole('super_admin');
    $this->owner = bureauLogin(BureauRole::Owner);
    $this->broker = staffOf($this->owner)->broker;
});

function referral(Broker $broker, array $attributes = []): BrokerReferral
{
    return BrokerReferral::factory()->create(['broker_id' => $broker->id, 'source' => ReferralSource::Referral, ...$attributes]);
}

/** A photo row on $profile with this pHash (no file — only the duplicate check reads it). */
function photoRow(Profile $profile, string $phash): App\Models\Media
{
    $media = new App\Models\Media;
    $media->forceFill(['model_type' => $profile->getMorphClass(), 'model_id' => $profile->id, 'uuid' => (string) Illuminate\Support\Str::uuid(),
        'collection_name' => Profile::PHOTOS, 'name' => 'p', 'file_name' => 'p.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'local', 'size' => 1,
        'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [], 'phash' => $phash])->save();

    return $media;
}

function loginEvent(string $userId, string $device, string $ip, ?Carbon\CarbonInterface $at = null): void
{
    (new LoginEvent)->forceFill(['user_id' => $userId, 'method' => 'PASSWORD', 'device_hash' => hash('sha256', $device),
        'ip_address' => $ip, 'created_at' => $at ?? now()])->save();
}

it('B.12 shared sign-ups: 3 referrals in 30 days from one device (or IP) is a signal; 2, or older ones, are not', function (): void {
    $device = hash('sha256', str_repeat('d', 40));
    referral($this->broker, ['signup_device_hash' => $device]);
    referral($this->broker, ['signup_device_hash' => $device]);
    referral($this->broker, ['signup_device_hash' => $device, 'created_at' => now()->subDays(40)]);
    expect(app(FraudSignals::class)->sharedSignups($this->broker))->toBeNull();

    referral($this->broker, ['signup_device_hash' => $device]);
    expect(app(FraudSignals::class)->sharedSignups($this->broker))->toMatchArray(['device' => 3]);
});

it('B.12 shared sign-ups: one mobile network (CGNAT) needs three times as many as one IP', function (): void {
    $net = SignupFingerprints::netHash('49.37.10.1');
    foreach (range(1, 8) as $i) {
        referral($this->broker, ['signup_net_hash' => $net, 'signup_ip_hash' => SignupFingerprints::ipHash('49.37.10.'.$i)]);
    }
    expect(app(FraudSignals::class)->sharedSignups($this->broker))->toBeNull();

    referral($this->broker, ['signup_net_hash' => $net]);
    expect(app(FraudSignals::class)->sharedSignups($this->broker))->toMatchArray(['network' => 9]);
});

it('B.12 self-referral: a referral signed up on a device or IP of the bureau’s own logins', function (): void {
    loginEvent($this->owner->id, str_repeat('o', 40), '103.21.4.5');
    $member = referredMember($this->broker);
    expect(app(FraudSignals::class)->selfReferral($this->broker))->toBeNull();

    BrokerReferral::query()->where('profile_id', $member->profile->id)->update(['signup_ip_hash' => SignupFingerprints::ipHash('103.21.4.5')]);
    expect(app(FraudSignals::class)->selfReferral($this->broker))->toBe(['matches' => 1, 'profiles' => [$member->profile->code]]);
});

it('B.12 referral spike: this month ≥ 3× the 3-month average and ≥ 10 — never for a bureau without 3 months of history', function (): void {
    $month = Carbon\CarbonImmutable::now('Asia/Kolkata')->startOfMonth();
    $this->broker->forceFill(['activated_at' => $month->subMonths(6)])->save();
    foreach (range(1, 6) as $i) {
        referral($this->broker, ['created_at' => $month->subMonths(2)->addDays($i)->utc()]);   // average 2
    }
    foreach (range(1, 9) as $i) {
        referral($this->broker, ['created_at' => $month->addHours($i)->utc()]);
    }
    expect(app(FraudSignals::class)->referralSpike($this->broker))->toBeNull();   // 9 < minimum 10

    referral($this->broker, ['created_at' => $month->addHours(20)->utc()]);
    expect(app(FraudSignals::class)->referralSpike($this->broker))->toBe(['month' => $month->format('Y-m'), 'this_month' => 10, 'monthly_average' => 2]);

    $this->broker->forceFill(['activated_at' => $month->subMonth()])->save();
    expect(app(FraudSignals::class)->referralSpike($this->broker))->toBeNull();
});

it('B.12 incomplete referrals and pay-then-refund', function (): void {
    foreach (range(1, 10) as $i) {
        $profile = Profile::factory()->create(['published_at' => $i <= 4 ? now()->subDays(50) : null]);
        referral($this->broker, ['profile_id' => $profile->id, 'created_at' => now()->subDays(45)]);
    }
    expect(app(FraudSignals::class)->incompleteReferrals($this->broker))->toBe(['referrals' => 10, 'never_live' => 6]);

    expect(app(FraudSignals::class)->payThenRefund($this->broker))->toBeNull();
    foreach ([2, 3] as $days) {
        referral($this->broker, ['status' => ReferralStatus::Void, 'void_reason' => ReferralVoidReason::Refund,
            'earned_at' => now()->subDays(10), 'voided_at' => now()->subDays(10 - $days)]);
    }
    referral($this->broker, ['status' => ReferralStatus::Void, 'void_reason' => ReferralVoidReason::Refund,
        'earned_at' => now()->subDays(40), 'voided_at' => now()->subDays(20)]);                    // refunded after 20 days
    expect(app(FraudSignals::class)->payThenRefund($this->broker))->toBe(['refunded' => 2]);
});

it('B.12 exports, rejections, disputes and staff devices', function (): void {
    settingIs(SettingKey::BrokerExportAnomalyPerHour, 3);
    $profile = managedProfile($this->broker);
    expect(app(FraudSignals::class)->exportPattern($this->broker))->toBeNull();
    foreach (range(1, 4) as $i) {
        BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id, 'user_id' => $this->owner->id, 'profile_id' => managedProfile($this->broker)->id, 'created_at' => now()->startOfHour()->addMinutes($i)]);
    }
    expect(app(FraudSignals::class)->exportPattern($this->broker))->toMatchArray(['burst_hours' => 1]);

    foreach (range(1, 10) as $i) {
        ModerationItem::factory()->create(['profile_id' => managedProfile($this->broker)->id, 'type' => ModerationItemType::ProfileNew,
            'status' => $i <= 4 ? ModerationStatus::Rejected : ModerationStatus::Approved, 'decided_at' => now()->subDay(),
            'reason_category' => $i <= 4 ? App\Enums\RejectReason::Misleading->value : null]);   // a real rejection carries its reason
    }
    // Held (consent dispute) and stale items are not decisions — never counted.
    ModerationItem::factory()->create(['profile_id' => managedProfile($this->broker)->id, 'type' => ModerationItemType::ProfileNew,
        'status' => ModerationStatus::Rejected, 'decided_at' => now()->subDay(), 'reason_category' => App\Enums\ModerationHold::ConsentDispute->value]);
    ModerationItem::factory()->create(['profile_id' => managedProfile($this->broker)->id, 'type' => ModerationItemType::ProfileNew,
        'status' => ModerationStatus::Rejected, 'decided_at' => now()->subDay(), 'reason_category' => 'STALE']);
    expect(app(FraudSignals::class)->highRejection($this->broker))->toBe(['decided' => 10, 'rejected' => 4]);

    expect(app(FraudSignals::class)->consentDisputes($this->broker))->toBeNull();
    BrokerConsentDispute::factory()->create(['broker_id' => $this->broker->id, 'profile_id' => $profile->id, 'status' => ConsentDisputeStatus::Open]);
    expect(app(FraudSignals::class)->consentDisputes($this->broker))->toBe(['open' => 1, 'upheld' => 0]);

    $staff = bureauLogin(BureauRole::DataEntry, broker: $this->broker);
    foreach (range(1, 4) as $i) {
        loginEvent($staff->id, str_repeat((string) $i, 40), '10.0.0.'.$i);
    }
    expect(app(FraudSignals::class)->staffDevices($this->broker))->toBeNull();
    loginEvent($staff->id, str_repeat('9', 40), '10.0.0.9');
    expect(app(FraudSignals::class)->staffDevices($this->broker))->toBe(['staff' => [staffOf($staff)->code]]);
});

it('B.12 import batch: 5 mobiles sharing their first 8 digits, or 5 consecutive numbers, are flagged once', function (): void {
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Ready]);
    foreach (['9847000011', '9847000012', '9847000013', '9847000014', '9847000019', '9123456789'] as $i => $mobile) {
        BrokerImportRow::factory()->create(['batch_id' => $batch->id, 'row_number' => $i + 2, 'data' => ['raw' => [], 'values' => ['client_mobile' => $mobile]]]);
    }

    expect(app(ImportBatchSignals::class)->analyse($batch))->toBeTrue()
        ->and($batch->refresh()->signal_flags)->toBe(['mobile_pattern' => 5])
        ->and(app(ImportBatchSignals::class)->analyse($batch))->toBeFalse()                     // worked out once
        ->and(app(FraudSignals::class)->batchFlag($this->broker, 'mobile_pattern'))->toBe(['batches' => [$batch->code], 'rows' => 5]);
});

it('B.12 import batch: an imported photo matching another profile flags the batch and escalates its A04 item', function (): void {
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Completed]);
    $imported = managedProfile($this->broker, ['import_batch_id' => $batch->id]);
    $other = Profile::factory()->create();
    $photo = photoRow($imported, 'a1b2c3d4e5f60718');
    photoRow($other, 'a1b2c3d4e5f60719');
    $item = ModerationItem::factory()->create(['profile_id' => $imported->id, 'type' => ModerationItemType::Photo, 'subject_id' => (string) $photo->id]);

    app(ImportBatchSignals::class)->analyse($batch);

    expect($batch->refresh()->signal_flags)->toMatchArray(['photo_matches' => 1])
        ->and($item->refresh()->status)->toBe(ModerationStatus::Escalated)
        ->and($item->reason_note)->toContain($batch->code);
});

it('★ B.12: a money signal opens once, holds payouts (audited) and is refreshed — never opened twice', function (): void {
    $device = hash('sha256', str_repeat('d', 40));
    foreach (range(1, 3) as $i) {
        referral($this->broker, ['signup_device_hash' => $device]);
    }

    expect(app(RecordFraudSignals::class)->handle($this->broker))->toBe([BrokerSignal::SharedSignups]);
    $this->broker->refresh();
    expect($this->broker->payouts_held_at)->not->toBeNull()
        ->and($this->broker->payouts_hold_reason)->toContain('Fraud signal')
        ->and(AuditLog::query()->where('action', 'brokers.payouts_held')->count())->toBe(1);

    referral($this->broker, ['signup_device_hash' => $device]);
    expect(app(RecordFraudSignals::class)->handle($this->broker))->toBe([])
        ->and(BrokerFraudSignal::query()->sole()->details)->toMatchArray(['device' => 4]);
});

it('B.12: a flag-only signal holds nothing; staff devices / exports tell the Owner (no details)', function (): void {
    $staff = bureauLogin(BureauRole::DataEntry, broker: $this->broker);
    foreach (range(1, 5) as $i) {
        loginEvent($staff->id, str_repeat((string) $i, 40), '10.0.0.'.$i);
    }

    expect(app(RecordFraudSignals::class)->handle($this->broker))->toBe([BrokerSignal::StaffDevices])
        ->and($this->broker->refresh()->payouts_held_at)->toBeNull();
    Notification::assertSentTo($this->owner, BureauSignalRaised::class, fn (BureauSignalRaised $n): bool => $n->signal === BrokerSignal::StaffDevices);
    Notification::assertNotSentTo($staff, BureauSignalRaised::class);
});

it('B.12: resolving a signal (brokers.edit, reason) keeps the payout hold; a later detection opens a new signal', function (): void {
    $signal = BrokerFraudSignal::factory()->create(['broker_id' => $this->broker->id, 'type' => BrokerSignal::ReferralSpike]);
    $this->broker->forceFill(['payouts_held_at' => now(), 'payouts_hold_reason' => 'Fraud signal'])->save();

    expect(fn () => app(ResolveFraudSignal::class)->handle(adminWithRole('finance'), $this->broker, $signal->id, 'Finance tries'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResolveFraudSignal::class)->handle($this->admin, Broker::factory()->create(), $signal->id, 'Wrong bureau'))->toThrow(ModelNotFoundException::class);

    app(ResolveFraudSignal::class)->handle($this->admin, $this->broker, $signal->id, 'Spike explained by a community event');
    expect($signal->refresh()->resolved_at)->not->toBeNull()->and($signal->open_key)->toBeNull()
        ->and($this->broker->refresh()->payouts_held_at)->not->toBeNull()
        ->and(fn () => app(ResolveFraudSignal::class)->handle($this->admin, $this->broker, $signal->id, 'Again resolved'))->toThrow(BrokerStateConflict::class);

    BrokerFraudSignal::factory()->create(['broker_id' => $this->broker->id, 'type' => BrokerSignal::ReferralSpike]);   // open again: allowed
    expect(BrokerFraudSignal::query()->count())->toBe(2);
});

it('B.12 void all: PENDING, EARNED and PAID commissions become VOID (FRAUD); attribution stays; brokers.payout only', function (): void {
    $pending = referral($this->broker);
    $earned = earnedCommission($this->broker, 10_000);
    $paid = referral($this->broker, ['status' => ReferralStatus::Paid, 'commission_paise' => 5_000]);
    $refunded = referral($this->broker, ['status' => ReferralStatus::Void, 'void_reason' => ReferralVoidReason::Refund]);

    expect(fn () => app(VoidAllReferrals::class)->handle(adminWithRole('support'), $this->broker, 'Self-referral confirmed'))->toThrow(AuthorizationException::class);

    $counts = app(VoidAllReferrals::class)->handle(adminWithRole('finance'), $this->broker, 'Self-referral confirmed');

    expect($counts)->toBe(['pending' => 1, 'earned' => 1, 'paid' => 1])
        ->and($pending->refresh()->void_reason)->toBe(ReferralVoidReason::Fraud)
        ->and($earned->refresh()->status)->toBe(ReferralStatus::Void)
        ->and($paid->refresh()->status)->toBe(ReferralStatus::Void)
        ->and($refunded->refresh()->void_reason)->toBe(ReferralVoidReason::Refund)
        ->and($pending->broker_id)->toBe($this->broker->id)
        ->and(AuditLog::query()->where('action', 'brokers.referrals_voided')->sole()->after)->toBe($counts);
});

it('the daily scan works through batches then every bureau, at 04:15 IST', function (): void {
    $device = hash('sha256', str_repeat('d', 40));
    foreach (range(1, 3) as $i) {
        referral($this->broker, ['signup_device_hash' => $device]);
    }
    $other = staffOf(bureauLogin(BureauRole::Owner))->broker;
    foreach (range(1, 3) as $i) {
        referral($other, ['signup_device_hash' => $device]);
    }

    (new ScanBrokerFraudSignals)->handle(app(ImportBatchSignals::class), app(RecordFraudSignals::class));

    expect(BrokerFraudSignal::query()->open()->pluck('broker_id')->sort()->values()->all())->toBe(collect([$this->broker->id, $other->id])->sort()->values()->all());

    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, 'ScanBrokerFraudSignals'));
    expect($event?->expression)->toBe('15 4 * * *')->and($event?->timezone)->toBe('Asia/Kolkata');
});

it('privacy: signal details carry codes and counts only — never a hash, IP or phone', function (): void {
    loginEvent($this->owner->id, str_repeat('o', 40), '103.21.4.5');
    $member = referredMember($this->broker);
    BrokerReferral::query()->where('profile_id', $member->profile->id)->update(['signup_device_hash' => hash('sha256', str_repeat('o', 40))]);

    app(RecordFraudSignals::class)->handle($this->broker);
    $json = json_encode(BrokerFraudSignal::query()->pluck('details')->all());

    expect($json)->toContain($member->profile->code)->not->toContain('103.21')->not->toContain(hash('sha256', str_repeat('o', 40)))->not->toContain('+91');
});

it('R-M13-1 / B.12 capture: registering with a code stores keyed hashes of the IP / network and the device — never the IP', function (): void {
    $profile = app(App\Actions\Auth\RegisterMember::class)->handle(registrationData([
        'phone' => App\ValueObjects\PhoneNumber::fromParts('91', '9876543210'), 'email' => null, 'referralCode' => $this->broker->code,
    ]), '127.0.0.1')->user?->profile;
    expect($profile)->not->toBeNull();
    $referral = BrokerReferral::query()->where('profile_id', $profile->id)->sole();

    expect($referral->signup_ip_hash)->toBe(SignupFingerprints::ipHash('127.0.0.1'))
        ->and($referral->signup_net_hash)->toBe(SignupFingerprints::netHash('127.0.0.1'))
        ->and($referral->signup_device_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and(json_encode($referral->getAttributes()))->not->toContain('127.0.0.1');
    expect(SignupFingerprints::netHash('127.0.0.9'))->toBe(SignupFingerprints::netHash('127.0.0.1'))
        ->and(SignupFingerprints::netHash('not-an-ip'))->toBeNull();
});

it('★ B.12: a resolved signal stays resolved — the same evidence never reopens it nor puts the payout hold back; new evidence does', function (): void {
    $admin = adminWithRole('super_admin');
    $device = hash('sha256', str_repeat('d', 40));
    foreach (range(1, 3) as $i) {
        referral($this->broker, ['signup_device_hash' => $device]);
    }
    app(RecordFraudSignals::class)->handle($this->broker);
    $signal = BrokerFraudSignal::query()->sole();

    app(ResolveFraudSignal::class)->handle($admin, $this->broker, $signal->id, 'Clients registered at the bureau office');
    app(App\Actions\Admin\Brokers\SetBrokerPayoutHold::class)->handle($admin, $this->broker->refresh(), false, 'Checked: office sign-ups');

    expect(app(RecordFraudSignals::class)->handle($this->broker->refresh()))->toBe([])        // the nightly rescan, same data
        ->and(BrokerFraudSignal::query()->open()->count())->toBe(0)
        ->and($this->broker->refresh()->payouts_held_at)->toBeNull();

    referral($this->broker, ['signup_device_hash' => $device]);                                 // one more on that device: new
    expect(app(RecordFraudSignals::class)->handle($this->broker))->toBe([BrokerSignal::SharedSignups])
        ->and($this->broker->refresh()->payouts_held_at)->not->toBeNull();
});

it('B.12: a resolved referral spike is reviewed once per IST month', function (): void {
    $type = BrokerSignal::ReferralSpike;
    expect(RecordFraudSignals::isNewEvidence($type, ['month' => '2026-10', 'this_month' => 40], ['month' => '2026-10', 'this_month' => 12]))->toBeFalse()
        ->and(RecordFraudSignals::isNewEvidence($type, ['month' => '2026-11', 'this_month' => 12], ['month' => '2026-10', 'this_month' => 40]))->toBeTrue()
        ->and(RecordFraudSignals::isNewEvidence(BrokerSignal::SelfReferral, ['profiles' => ['OPM1', 'OPM2']], ['profiles' => ['OPM1', 'OPM2']]))->toBeFalse()
        ->and(RecordFraudSignals::isNewEvidence(BrokerSignal::SelfReferral, ['profiles' => ['OPM1', 'OPM3']], ['profiles' => ['OPM1', 'OPM2']]))->toBeTrue();
});

it('B.12 capture (rule 5): the device cookie already on the browser is used and kept; a browser without one gets one', function (): void {
    $cookie = str_repeat('k', 40);
    $this->app['request']->cookies->set((string) config('oppam.auth.device_cookie'), $cookie);
    $fingerprint = app(SignupFingerprints::class)->current();

    expect($fingerprint['signup_device_hash'])->toBe(hash('sha256', $cookie))
        ->and(app(Illuminate\Contracts\Cookie\QueueingFactory::class)->hasQueued((string) config('oppam.auth.device_cookie')))->toBeFalse();

    $this->app['request']->cookies->remove((string) config('oppam.auth.device_cookie'));
    $fresh = app(SignupFingerprints::class)->current();
    $queued = app(Illuminate\Contracts\Cookie\QueueingFactory::class)->queued((string) config('oppam.auth.device_cookie'));

    expect($queued)->not->toBeNull()
        ->and($fresh['signup_device_hash'])->toBe(hash('sha256', (string) $queued?->getValue()))
        ->and($queued?->isHttpOnly())->toBeTrue();
});

it('B.12 import batch: a photo matching a banned member’s photo counts as a match too', function (): void {
    App\Models\BannedIdentifier::factory()->create(['type' => App\Enums\BannedIdentifierType::Phash, 'value' => 'b1b2c3d4e5f60718']);
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Completed]);
    photoRow(managedProfile($this->broker, ['import_batch_id' => $batch->id]), 'b1b2c3d4e5f60718');

    app(ImportBatchSignals::class)->analyse($batch);

    expect($batch->refresh()->signal_flags)->toMatchArray(['photo_matches' => 1]);
});
