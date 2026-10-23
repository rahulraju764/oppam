<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\ApproveBrokerKyc;
use App\Actions\Admin\Brokers\OpenBrokerPanImage;
use App\Actions\Admin\Brokers\RejectBrokerApplication;
use App\Actions\Admin\Brokers\RejectBrokerKyc;
use App\Actions\Broker\SubmitBrokerKyc;
use App\Contracts\DocumentVault;
use App\Data\Broker\BrokerKycData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\KycQueue;
use App\Domain\Broker\PanNumber;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\KycSubmissionStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Models\AuditLog;
use App\Models\BrokerKyc;
use App\Models\User;
use App\Notifications\Broker\BrokerKycDecided;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.1 — bureau KYC (PRD §11A B.3, B.13, B.18): the Owner sends PAN (number + image) and bank
| details — encrypted, private, masked; A07 approves (bureau ACTIVE, OWNER login ACTIVE) or
| rejects (KYC / application); the PAN image only through a signed, session-bound, audited link.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    fakeVerificationDisk();
    Storage::fake('public');
    Notification::fake();
});

/** An applicant Owner whose application PAN is TEST_PAN. */
function kycApplicant(): User
{
    $owner = applicantOwner();
    $owner->brokerStaff->broker->forceFill(['pan_hash' => PanNumber::hash(TEST_PAN), 'pan_last4' => '234F'])->save();

    return $owner->refresh();
}

function kycData(array $overrides = []): BrokerKycData
{
    return new BrokerKycData(...array_merge([
        'pan' => TEST_PAN,
        'panImage' => idPhoto('pan.jpg'),
        'accountNumber' => '123456789012',
        'ifsc' => 'SBIN0001234',
        'accountName' => 'Rajan Pillai',
    ], $overrides));
}

function sentKyc(?User $owner = null): BrokerKyc
{
    return app(SubmitBrokerKyc::class)->handle($owner ?? kycApplicant(), kycData());
}

it('B.3: the Owner sends KYC — PAN and account encrypted at rest, only last 4 in the clear, PAN image in the vault', function (): void {
    Event::fake([AdminQueueCountChanged::class]);
    $owner = kycApplicant();

    $kyc = app(SubmitBrokerKyc::class)->handle($owner, kycData());
    $broker = $owner->brokerStaff->broker->refresh();

    expect($kyc->status)->toBe(KycSubmissionStatus::Pending)
        ->and($kyc->pan_last4)->toBe('234F')->and($kyc->bank_last4)->toBe('9012')
        ->and($kyc->pan_number_encrypted)->toBe(TEST_PAN)                     // decrypts through the cast
        ->and($broker->status)->toBe(BrokerStatus::KycPending)
        ->and($broker->kyc_status)->toBe(BrokerKycStatus::Pending);

    $raw = json_encode(DB::table('broker_kyc')->first());
    expect($raw)->not->toContain(TEST_PAN)->not->toContain('123456789012')
        ->and(Storage::disk((string) config('oppam.verification.disk'))->get((string) $kyc->pan_image_path))->not->toContain("\xFF\xD8\xFF")
        ->and(app(DocumentVault::class)->get((string) $kyc->pan_image_path))->toStartWith("\xFF\xD8")
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $log = AuditLog::query()->where('action', 'broker.kyc_submitted')->sole();
    expect(json_encode($log->after))->not->toContain(TEST_PAN)->not->toContain('123456789012')->toContain('XXXXXX234F');
    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === KycQueue::QUEUE_KEY && $e->count === 1);
});

it('validates PAN, the PAN image, account number, IFSC and name — and writes nothing', function (array $overrides, string $field): void {
    $owner = kycApplicant();

    try {
        app(SubmitBrokerKyc::class)->handle($owner, kycData($overrides));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(BrokerKyc::query()->count())->toBe(0)->and(Storage::disk((string) config('oppam.verification.disk'))->allFiles())->toBe([]);
})->with([
    'bad PAN shape' => [['pan' => 'ABC123'], 'pan'],
    'a different PAN than in the application' => [['pan' => 'PQRST6789K'], 'pan'],
    'no image' => [['panImage' => null], 'panImage'],
    'script renamed .jpg' => [['panImage' => UploadedFile::fake()->createWithContent('pan.jpg', '<?php echo 1;')], 'panImage'],
    'account with letters' => [['accountNumber' => '12AB5678901'], 'accountNumber'],
    'account too short' => [['accountNumber' => '12345'], 'accountNumber'],
    'bad IFSC' => [['ifsc' => 'SBIN1234567'], 'ifsc'],
    'name with digits' => [['accountName' => 'Rajan 2'], 'accountName'],
]);

it('R-M13-24: only the Owner sends KYC (403); once under review or verified it can’t be sent again', function (): void {
    $owner = kycApplicant();
    $manager = bureauLogin(BureauRole::Manager, broker: $owner->brokerStaff->broker);

    expect(fn () => app(SubmitBrokerKyc::class)->handle($manager, kycData()))->toThrow(HttpException::class);

    sentKyc($owner);
    app(BureauGate::class)->forget();
    expect(fn () => app(SubmitBrokerKyc::class)->handle($owner->refresh(), kycData()))->toThrow(ValidationException::class);

    $verified = bureauLogin(BureauRole::Owner);
    expect(fn () => app(SubmitBrokerKyc::class)->handle($verified, kycData()))->toThrow(ValidationException::class)
        ->and(BrokerKyc::query()->count())->toBe(1);
});

it('rule 10: KYC is capped at oppam.broker.kyc_submissions_per_day per bureau — nothing stored past it', function (): void {
    $owner = kycApplicant();
    foreach (range(1, (int) config('oppam.broker.kyc_submissions_per_day')) as $i) {
        app(Illuminate\Cache\RateLimiter::class)->hit('broker-kyc:'.$owner->brokerStaff->broker_id, 86400);
    }

    try {
        app(SubmitBrokerKyc::class)->handle($owner, kycData());
        $this->fail('Expected the daily cap.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('panImage');
    }

    expect(BrokerKyc::query()->count())->toBe(0)
        ->and(Storage::disk((string) config('oppam.verification.disk'))->allFiles())->toBe([]);
});

it('a suspended or rejected bureau can’t send KYC (403 BROKER_INACTIVE) — nothing stored', function (string $bureau): void {
    $owner = bureauLogin(BureauRole::Owner, $bureau);

    try {
        app(SubmitBrokerKyc::class)->handle($owner, kycData());
        $this->fail('Expected a 403.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403)
            ->and($e->getHeaders()['X-Error-Code'] ?? null)->toBe(BureauGate::INACTIVE);
    }

    expect(BrokerKyc::query()->count())->toBe(0)
        ->and(Storage::disk((string) config('oppam.verification.disk'))->allFiles())->toBe([]);
})->with(['suspended', 'rejected', 'deactivated']);

it('P7.1 review: an application whose mobile was never proved is not in the A07 list, count or A02 queue', function (): void {
    $proved = applicantOwner();
    $unproved = applicantOwner();
    $unproved->forceFill(['phone_verified_at' => null])->save();

    expect(KycQueue::waitingCount())->toBe(1)
        ->and(App\Models\Broker::query()->applicationsToReview()->pluck('id')->all())->toBe([$proved->brokerStaff->broker_id]);
});

it('removes the PAN image again when the row can’t be saved', function (): void {
    BrokerKyc::creating(fn () => throw new RuntimeException('boom'));

    expect(fn () => sentKyc())->toThrow(RuntimeException::class)
        ->and(Storage::disk((string) config('oppam.verification.disk'))->allFiles())->toBe([]);
});

it('R-A07-2 / P7.1: approval makes the bureau ACTIVE + VERIFIED and the OWNER login ACTIVE; creation opens', function (): void {
    $owner = kycApplicant();
    $kyc = sentKyc($owner);
    $admin = adminWithRole('super_admin');

    app(ApproveBrokerKyc::class)->handle($admin, $kyc);

    $broker = $owner->brokerStaff->broker->refresh();
    expect($broker->status)->toBe(BrokerStatus::Active)
        ->and($broker->kyc_status)->toBe(BrokerKycStatus::Verified)
        ->and($broker->activated_at)->not->toBeNull()
        ->and($owner->brokerStaff->refresh()->status)->toBe(BureauStaffStatus::Active)
        ->and($kyc->refresh()->status)->toBe(KycSubmissionStatus::Verified)
        ->and($kyc->reviewed_by_admin_id)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'brokers.kyc_approved')->sole()->actor_id)->toBe($admin->id);
    Notification::assertSentTo($owner, BrokerKycDecided::class, fn (BrokerKycDecided $n): bool => $n->outcome === BrokerKycDecided::APPROVED);

    actingAsBureau($owner->refresh());
    $this->get(brokerUrl('/profiles/create'))->assertOk();

    // A second decision on the same submission is refused.
    expect(fn () => app(RejectBrokerKyc::class)->handle($admin, $kyc, 'Blurred PAN photo'))->toThrow(ValidationException::class);
});

it('A07 reject KYC: reason required and shown to the owner, who may send again', function (): void {
    $owner = kycApplicant();
    $kyc = sentKyc($owner);
    $admin = adminWithRole('super_admin');

    expect(fn () => app(RejectBrokerKyc::class)->handle($admin, $kyc, 'no'))->toThrow(ValidationException::class);
    app(RejectBrokerKyc::class)->handle($admin, $kyc, 'The PAN photo is blurred.');

    expect($kyc->refresh()->status)->toBe(KycSubmissionStatus::Rejected)
        ->and($kyc->rejection_reason)->toBe('The PAN photo is blurred.')
        ->and($owner->brokerStaff->broker->refresh()->kyc_status)->toBe(BrokerKycStatus::Rejected);
    Notification::assertSentTo($owner, BrokerKycDecided::class, fn (BrokerKycDecided $n): bool => $n->outcome === BrokerKycDecided::KYC_REJECTED);

    app(BureauGate::class)->forget();
    expect(sentKyc($owner->refresh())->status)->toBe(KycSubmissionStatus::Pending);
});

it('A07 reject application: the bureau is REJECTED, its logins deactivated and signed out on the next request', function (): void {
    $owner = kycApplicant();
    actingAsBureau($owner);
    $this->get(brokerUrl(''))->assertOk();

    app(RejectBrokerApplication::class)->handle(adminWithRole('super_admin'), $owner->brokerStaff->broker, 'Not a marriage bureau.');

    expect($owner->brokerStaff->broker->refresh()->status)->toBe(BrokerStatus::Rejected)
        ->and($owner->brokerStaff->refresh()->status)->toBe(BureauStaffStatus::Deactivated);
    $this->get(brokerUrl(''))->assertRedirect(memberUrl('/login'));

    // An active bureau is never "rejected" (it is suspended / deactivated in A07).
    expect(fn () => app(RejectBrokerApplication::class)->handle(adminWithRole('super_admin'), bureauLogin()->brokerStaff->broker, 'Changed our mind'))
        ->toThrow(ValidationException::class);
});

it('KYC decisions need brokers.kyc', function (string $role): void {
    $kyc = sentKyc();

    expect(fn () => app(ApproveBrokerKyc::class)->handle(adminWithRole($role), $kyc))->toThrow(AuthorizationException::class)
        ->and(fn () => app(OpenBrokerPanImage::class)->handle(adminWithRole($role), $kyc))->toThrow(AuthorizationException::class)
        ->and($kyc->refresh()->status)->toBe(KycSubmissionStatus::Pending);
})->with(['moderator', 'support', 'finance', 'verification_officer', 'read_only']);

it('B.18: the PAN image only through a signed, session-bound link; every fetch audited', function (): void {
    $kyc = sentKyc();
    $code = $kyc->broker->code;
    $admin = adminWithRole('super_admin');
    signInAdmin($this, $admin);

    $url = app(OpenBrokerPanImage::class)->handle($admin, $kyc);
    expect($url)->toContain('/brokers/kyc/'.$code.'/pan')->not->toContain($kyc->id);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
    $this->get($url)->assertOk();
    expect(AuditLog::query()->where('action', 'brokers.kyc_pan_viewed')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'brokers.kyc_pan_opened')->count())->toBe(1);

    $this->get(strtok($url, '?'))->assertForbidden();
    $this->withHeader('Sec-Fetch-Dest', 'document')->get($url)->assertNotFound();

    $this->flushSession();
    signInAdmin($this, adminWithRole('super_admin'));
    $this->get($url)->assertNotFound();                       // a copied link, another admin

    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
    expect(AuditLog::query()->where('action', 'brokers.kyc_pan_viewed')->count())->toBe(2);
});
