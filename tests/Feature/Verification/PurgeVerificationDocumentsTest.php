<?php

declare(strict_types=1);

use App\Actions\Admin\Members\DeleteMember;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Admin\Verification\ApproveVerification;
use App\Actions\Admin\Verification\RejectVerification;
use App\Actions\Verification\PurgeVerificationDocuments;
use App\Contracts\DocumentVault;
use App\Enums\SettingKey;
use App\Enums\VerificationRejectReason;
use App\Enums\VerificationStatus;
use App\Jobs\Members\PurgeDeletedMembers;
use App\Jobs\Verification\PurgeExpiredVerificationDocuments;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\VerificationDocument;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Verification\EncryptedDocumentVault;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| P6.1 — M09 "originals deleted 90 days after decision (only the decision + masked number kept)",
| A05 "daily purge job verified", and the A03 member purge (P1.7a → P6.1).
*/

beforeEach(function (): void {
    seedAdminRoles();
    fakeVerificationDisk();
    Notification::fake();
});

function runVerificationPurge(): void
{
    (new PurgeExpiredVerificationDocuments)->handle(app(PurgeVerificationDocuments::class), app(AuditLogger::class));
}

function vaultFiles(): array
{
    return Storage::disk((string) config('oppam.verification.disk'))->allFiles();
}

it('M09: deletes the files 90 days after the decision, verifies they are gone, keeps the decision and the masked number', function (): void {
    $approved = submitId(verificationMember(), ['back' => idPdf()]);
    app(ApproveVerification::class)->handle(adminWithRole('verification_officer'), $approved);
    $rejected = submitId(verificationMember());
    app(RejectVerification::class)->handle(adminWithRole('verification_officer'), $rejected, VerificationRejectReason::Expired);
    $waiting = submitId(verificationMember());
    expect(vaultFiles())->toHaveCount(4);

    $this->travel(89)->days();
    runVerificationPurge();
    expect(vaultFiles())->toHaveCount(4)->and(AuditLog::query()->where('action', 'verification.documents_purged')->count())->toBe(0);

    $this->travel(2)->days();
    runVerificationPurge();

    expect(vaultFiles())->toHaveCount(1);   // only the request still waiting keeps its file
    foreach ([$approved, $rejected] as $request) {
        $request->refresh();
        expect($request->documents_purged_at)->not->toBeNull()
            ->and($request->name_on_document)->toBeNull()
            ->and($request->number_last4)->toBe('0124')                     // the masked number stays
            ->and($request->status)->not->toBe(VerificationStatus::Pending)  // so does the decision
            ->and($request->documents->every(fn (VerificationDocument $d): bool => $d->purged_at !== null && $d->path === null && $d->sha256 === null))->toBeTrue();
    }
    expect($waiting->refresh()->documents_purged_at)->toBeNull();

    $log = AuditLog::query()->where('action', 'verification.documents_purged')->sole();
    expect($log->after)->toBe(['requests' => 2, 'not_confirmed' => 0]);

    // Idempotent: a second run finds nothing to do.
    runVerificationPurge();
    expect(AuditLog::query()->where('action', 'verification.documents_purged')->count())->toBe(1);
});

it('A05 verified purge: a file the disk still has is NOT marked purged — logged and retried next run', function (): void {
    $request = submitId(verificationMember());
    app(RejectVerification::class)->handle(adminWithRole('verification_officer'), $request, VerificationRejectReason::Unreadable);
    $this->travel(91)->days();

    // A disk that "accepts" the delete but keeps the file.
    $stubborn = new class(app(EncryptedDocumentVault::class)) implements DocumentVault
    {
        public function __construct(private readonly DocumentVault $inner) {}

        public function put(string $contents): string
        {
            return $this->inner->put($contents);
        }

        public function get(string $path): string
        {
            return $this->inner->get($path);
        }

        public function delete(string $path): void {}

        public function exists(string $path): bool
        {
            return $this->inner->exists($path);
        }

        public function disk(): string
        {
            return $this->inner->disk();
        }
    };
    app()->instance(DocumentVault::class, $stubborn);
    Log::spy();

    runVerificationPurge();

    expect($request->refresh()->documents_purged_at)->toBeNull()
        ->and($request->documents->first()?->path)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'verification.documents_purged')->sole()->after)->toBe(['requests' => 0, 'not_confirmed' => 1]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'verification.purge_failed' && ! str_contains(json_encode($context), 'verification/'));

    app()->forgetInstance(DocumentVault::class);
    app()->bind(DocumentVault::class, EncryptedDocumentVault::class);
    runVerificationPurge();
    expect($request->refresh()->documents_purged_at)->not->toBeNull()->and(vaultFiles())->toBe([]);
});

it('an admin may shorten the window (verification.purge_after_days), never past 90', function (): void {
    Setting::factory()->keyed(SettingKey::VerificationPurgeAfterDays, 30)->create();
    $request = submitId(verificationMember());
    app(RejectVerification::class)->handle(adminWithRole('verification_officer'), $request, VerificationRejectReason::Unreadable);

    $this->travel(31)->days();
    runVerificationPurge();

    expect($request->refresh()->documents_purged_at)->not->toBeNull()
        ->and(validator(['v' => 91], ['v' => SettingKey::VerificationPurgeAfterDays->rules()])->fails())->toBeTrue();
});

it('A03 purge: a purged member’s ID files go at once and a waiting request is cancelled', function (): void {
    $member = verificationMember();
    $request = submitId($member, ['back' => idPdf()]);
    app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Member asked us to delete it.');

    expect(VerificationRequest::waitingCount())->toBe(0);   // a deleted member's request leaves the queue

    $this->travel(31)->days();
    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));

    expect(vaultFiles())->toBe([])
        ->and($request->refresh()->status)->toBe(VerificationStatus::Cancelled)
        ->and($request->documents_purged_at)->not->toBeNull()
        ->and($request->name_on_document)->toBeNull()
        ->and($request->flags)->toBeNull();
});
