<?php

declare(strict_types=1);

use App\Actions\Verification\SubmitVerification;
use App\Data\Verification\VerificationSubmissionData;
use App\Models\Profile;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| P6.1 ID verification test helpers. A real ID number never appears in tests: these numbers only
| satisfy the Aadhaar format and its Verhoeff check digit.
*/

const TEST_AADHAAR = '2345 6789 0124';

const TEST_AADHAAR_OTHER = '987654321096';

/** Fake the private disk the DocumentVault writes to. */
function fakeVerificationDisk(): void
{
    Storage::fake((string) config('oppam.verification.disk'));
}

/** An active member with an ACTIVE profile (attributes for the profile). */
function verificationMember(array $profile = []): User
{
    return Profile::factory()->create($profile)->user()->firstOrFail();
}

function idPhoto(string $name = 'aadhaar.jpg', int $width = 900, int $height = 600): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

function idPdf(string $body = '1 0 obj << /Type /Catalog >> endobj'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('aadhaar.pdf', "%PDF-1.4\n{$body}\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/** @param  array<string, mixed>  $overrides */
function idSubmission(User $member, array $overrides = []): VerificationSubmissionData
{
    return new VerificationSubmissionData(...array_merge([
        'docType' => 'AADHAAR',
        'number' => TEST_AADHAAR,
        'nameOnDocument' => (string) $member->profile?->fullName(),
        'front' => idPhoto(),
        'back' => null,
        'consent' => true,
    ], $overrides));
}

/** @param  array<string, mixed>  $overrides */
function submitId(User $member, array $overrides = []): VerificationRequest
{
    return app(SubmitVerification::class)->handle($member, idSubmission($member, $overrides));
}
