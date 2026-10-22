<?php

declare(strict_types=1);

use App\Contracts\DocumentVault;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationFlag;
use App\Enums\VerificationStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\AuditLog;
use App\Models\Profile;
use App\Models\VerificationDocument;
use App\Models\VerificationRequest;
use App\Services\Admin\Impersonation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P6.1 — M09 ID upload: SubmitVerification (masked Aadhaar, front/back, consent, private encrypted
| storage, fraud flags, one open request, rate limit) and the security matrix around it.
*/

beforeEach(function (): void {
    fakeVerificationDisk();
    Storage::fake('public');
});

function storedFiles(): array
{
    return Storage::disk((string) config('oppam.verification.disk'))->allFiles();
}

it('M09: stores front and back encrypted on the private disk under random names and keeps only the last 4 digits', function (): void {
    $member = verificationMember(['first_name' => 'Anjali', 'last_name' => 'Nair']);

    $request = submitId($member, ['back' => idPdf()]);

    expect($request->status)->toBe(VerificationStatus::Pending)
        ->and($request->number_last4)->toBe('0124')
        ->and($request->maskedNumber())->toBe('XXXX XXXX 0124')
        ->and($request->number_hash)->toHaveLength(64)
        ->and($request->documents)->toHaveCount(2);

    // The full number is in no column of the row (it was never stored).
    $raw = json_encode(DB::table('verification_requests')->where('id', $request->id)->first());
    expect($raw)->not->toContain('234567890124')->and($raw)->not->toContain('2345 6789');

    foreach ($request->documents as $document) {
        $stored = Storage::disk($document->disk)->get((string) $document->path);

        expect($document->path)->toStartWith('verification/')->not->toContain('.')
            ->and($stored)->not->toStartWith("\xFF\xD8")->not->toStartWith('%PDF')   // not the plain file
            ->and(app(DocumentVault::class)->get((string) $document->path))->toStartWith($document->isPdf() ? '%PDF' : "\xFF\xD8");
    }

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('M09: re-encodes photos (a script renamed .jpg, an SVG or HTML is refused) and refuses active or locked PDFs', function (UploadedFile $file, string $field): void {
    $member = verificationMember();

    expect(fn () => submitId($member, [$field => $file]))->toThrow(ValidationException::class)
        ->and(VerificationRequest::query()->count())->toBe(0)
        ->and(storedFiles())->toBe([]);
})->with([
    'php as jpg' => fn () => [UploadedFile::fake()->createWithContent('id.jpg', '<?php echo "x";'), 'front'],
    'svg' => fn () => [UploadedFile::fake()->createWithContent('id.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'front'],
    'html as png' => fn () => [UploadedFile::fake()->createWithContent('id.png', '<html><body>hi</body></html>'), 'front'],
    'pdf with javascript' => fn () => [idPdf('1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj'), 'front'],
    'password pdf' => fn () => [idPdf('trailer << /Encrypt 5 0 R >>'), 'back'],
    'too large (6 MB)' => fn () => [UploadedFile::fake()->create('id.pdf', 6 * 1024, 'application/pdf'), 'front'],
    'tiny photo' => fn () => [idPhoto('id.jpg', 120, 80), 'front'],
]);

it('validates type, number shape, Aadhaar check digit, name and consent — and writes nothing', function (array $overrides, string $error): void {
    $member = verificationMember();

    try {
        submitId($member, $overrides);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }

    expect(VerificationRequest::query()->count())->toBe(0)->and(storedFiles())->toBe([]);
})->with([
    'no type' => [['docType' => ''], 'docType'],
    'unknown type' => [['docType' => 'PAN'], 'docType'],
    'aadhaar wrong length' => [['number' => '1234 5678'], 'number'],
    'aadhaar bad check digit' => [['number' => '234567890125'], 'number'],
    'aadhaar starting with 1' => [['number' => '123456789012'], 'number'],
    'passport shape' => [['docType' => 'PASSPORT', 'number' => '12345678'], 'number'],
    'voter id shape' => [['docType' => 'VOTER_ID', 'number' => 'AB1234567'], 'number'],
    'no front' => [['front' => null], 'front'],
    'no consent' => [['consent' => false], 'consent'],
    'name with digits' => [['nameOnDocument' => 'Anjali 123'], 'nameOnDocument'],
    'name missing' => [['nameOnDocument' => ' '], 'nameOnDocument'],
]);

it('accepts the other document types in their printed formats', function (string $type, string $number, string $last4): void {
    $member = verificationMember();

    expect(submitId($member, ['docType' => $type, 'number' => $number])->number_last4)->toBe($last4);
})->with([
    'passport' => ['PASSPORT', 'k 1234567', '4567'],
    'driving licence' => ['DRIVING_LICENCE', 'KL07 20110012345', '2345'],
    'voter id' => ['VOTER_ID', 'abc-1234567', '4567'],
]);

it('allows one open request at a time and none once verified', function (): void {
    $member = verificationMember();
    submitId($member);

    expect(fn () => submitId($member))->toThrow(ValidationException::class);

    $verified = verificationMember();
    $verified->profile->forceFill(['is_verified' => true])->save();
    expect(fn () => submitId($verified->refresh()))->toThrow(ValidationException::class)
        ->and(VerificationRequest::query()->count())->toBe(1);
});

it('may send again after a rejection, up to verification.max_submissions_per_day (rule 10)', function (): void {
    $member = verificationMember();

    foreach (range(1, 3) as $i) {
        submitId($member)->forceFill(['status' => VerificationStatus::Rejected, 'decided_at' => now()])->save();
    }

    expect(fn () => submitId($member))->toThrow(ValidationException::class)
        ->and(VerificationRequest::query()->count())->toBe(3);

    $this->travel(25)->hours();
    expect(submitId($member)->status)->toBe(VerificationStatus::Pending);
});

it('refuses guests-by-role, suspended accounts and admin support sessions', function (): void {
    $broker = verificationMember();
    $broker->forceFill(['role' => UserRole::Broker])->save();
    expect(fn () => submitId($broker))->toThrow(NotFoundHttpException::class);

    $suspended = verificationMember();
    $suspended->forceFill(['status' => UserStatus::Suspended])->save();
    expect(fn () => submitId($suspended))->toThrow(NotFoundHttpException::class);

    $member = verificationMember();
    session()->put(Impersonation::SESSION_KEY, 'support-session');
    expect(fn () => submitId($member))->toThrow(ImpersonationRestricted::class);

    expect(VerificationRequest::query()->count())->toBe(0)->and(storedFiles())->toBe([]);
});

it('A05 fraud flags: same file or number on another profile, name mismatch, banned name + DOB, rapid resubmission', function (): void {
    $first = verificationMember(['first_name' => 'Anjali', 'last_name' => 'Nair', 'dob' => '1995-04-01']);
    $photo = idPhoto();
    submitId($first, ['front' => $photo, 'nameOnDocument' => 'Anjali K']);

    $banned = verificationMember(['first_name' => 'Divya', 'last_name' => 'Menon', 'dob' => '1994-02-02']);
    $banned->forceFill(['status' => UserStatus::Banned])->save();

    $second = verificationMember(['first_name' => 'Divya', 'last_name' => 'Menon', 'dob' => '1994-02-02', 'is_premium' => true]);
    $request = submitId($second, ['front' => UploadedFile::fake()->createWithContent('copy.jpg', (string) file_get_contents($photo->getRealPath())), 'nameOnDocument' => 'Lakshmi Pillai']);

    expect($request->flagCodes())->toEqualCanonicalizing([
        VerificationFlag::DuplicateDocument, VerificationFlag::DuplicateNumber, VerificationFlag::NameMismatch, VerificationFlag::BannedNameMatch,
    ])->and($request->is_priority)->toBeTrue()                                   // A05 paid-first
        ->and(json_encode($request->flags))->toContain($first->profile->code)->toContain($banned->profile->code);

    // The first member's own request: initials are not a mismatch (Malayali naming variants).
    expect(VerificationRequest::query()->where('profile_id', $first->profile->id)->first()?->flagCodes())->toBe([]);

    $request->forceFill(['status' => VerificationStatus::Rejected])->save();
    submitId($second)->forceFill(['status' => VerificationStatus::Rejected])->save();
    expect(submitId($second)->flagCodes())->toContain(VerificationFlag::RapidResubmission);
});

it('F05: the A05 badge goes up live (+1) with the queue key and count only', function (): void {
    Event::fake([AdminQueueCountChanged::class]);

    submitId(verificationMember());

    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === 'verification' && $e->count === 1
        && $e->broadcastWith() === ['queue' => 'verification', 'count' => 1]);
});

it('audits the submission without the number or the files', function (): void {
    $member = verificationMember();
    $request = submitId($member);

    $log = AuditLog::query()->where('action', 'verification.submitted')->sole();
    expect($log->actor_id)->toBe($member->id)
        ->and($log->subject_id)->toBe($request->id)
        ->and(json_encode($log->after))->not->toContain('0124')->not->toContain('verification/');
});

it('removes the stored files again when the request can’t be saved', function (): void {
    // The row fails to save after both files were written to the vault.
    VerificationRequest::creating(fn () => throw new RuntimeException('boom'));

    expect(fn () => submitId(verificationMember()))->toThrow(RuntimeException::class)
        ->and(VerificationRequest::query()->count())->toBe(0)
        ->and(VerificationDocument::query()->count())->toBe(0)
        ->and(storedFiles())->toBe([]);
});

it('a deleted profile has no upload', function (): void {
    $member = verificationMember();
    Profile::query()->whereKey($member->profile->id)->delete();

    expect(fn () => submitId($member->refresh()))->toThrow(NotFoundHttpException::class);
});
