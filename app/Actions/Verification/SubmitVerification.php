<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Contracts\DocumentVault;
use App\Data\Verification\VerificationSubmissionData;
use App\Domain\Verification\DocumentNumber;
use App\Domain\Verification\VerificationFlags;
use App\Domain\Verification\VerificationQueue;
use App\Enums\DocumentSide;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Enums\VerificationDocType;
use App\Enums\VerificationStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Profile;
use App\Models\User;
use App\Models\VerificationDocument;
use App\Models\VerificationRequest;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use App\Services\Media\ImageSanitizer;
use App\Support\Facades\Settings;
use finfo;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Send an ID for verification (PRD M09, F05): document type, number, the name as printed, front
 * (and optionally back) as JPG / PNG / PDF ≤ 5 MB, and consent. Rules:
 *  - the member's own active account, never in an admin support session (A01: no documents on a
 *    member's behalf); one open request at a time; not while already verified;
 *  - the number must have the document's shape (Aadhaar: 12 digits + Verhoeff check) — only its
 *    last 4 and a keyed hash are kept (DocumentNumber);
 *  - files are sniffed (never the browser's word); images are re-encoded (EXIF / GPS dropped,
 *    scripts refused), PDFs must be plain (no password, JavaScript or launch actions);
 *  - files are encrypted into the DocumentVault before the row exists, removed again if it fails;
 *  - fraud flags are computed once (VerificationFlags); the A05 badge goes up live;
 *  - `verification.max_submissions_per_day` per member (rule 10).
 */
final class SubmitVerification
{
    private const MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(
        private readonly DocumentVault $vault,
        private readonly ImageSanitizer $sanitizer,
        private readonly VerificationFlags $flags,
        private readonly VerificationQueue $queue,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws ValidationException
     */
    public function handle(User $actor, VerificationSubmissionData $data): VerificationRequest
    {
        $this->impersonation->assertAllowed();

        $profile = $actor->role === UserRole::Member && $actor->isActive() ? $actor->profile : null;
        if (! $profile instanceof Profile) {
            abort(404);
        }

        $type = $this->validate($data);
        $number = DocumentNumber::parse($type, $data->number)
            ?? throw ValidationException::withMessages(['number' => __('This doesn’t look like a valid :type number. :hint', ['type' => $type->label(), 'hint' => $type->numberHint()])]);

        $key = 'verification-submit:'.$actor->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::VerificationMaxSubmissionsPerDay))) {
            throw ValidationException::withMessages(['front' => __('You have sent several documents today. Please try again tomorrow.')]);
        }

        $files = array_filter([DocumentSide::Front->value => $data->front, DocumentSide::Back->value => $data->back]);
        $prepared = [];
        foreach ($files as $side => $file) {
            $prepared[$side] = $this->prepare($file, $side === DocumentSide::Front->value ? 'front' : 'back');
        }

        $stored = [];
        try {
            foreach ($prepared as $side => $file) {
                $stored[$side] = $this->vault->put($file['bytes']);
            }

            $request = DB::transaction(fn (): VerificationRequest => $this->create($actor, $profile, $type, $number, trim($data->nameOnDocument), $prepared, $stored));
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                rescue(fn () => $this->vault->delete($path), report: true);
            }

            throw $e;
        }

        $this->limiter->hit($key, 86400);

        return $request;
    }

    /** @throws ValidationException */
    private function validate(VerificationSubmissionData $data): VerificationDocType
    {
        $file = ['file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:'.implode(',', self::MIMES), 'max:'.(int) config('oppam.verification.max_kb')];

        Validator::make([
            'docType' => $data->docType,
            'number' => $data->number,
            'nameOnDocument' => trim($data->nameOnDocument),
            'front' => $data->front,
            'back' => $data->back,
            'consent' => $data->consent,
        ], [
            'docType' => ['required', Rule::enum(VerificationDocType::class)],
            'number' => ['required', 'string', 'max:30'],
            'nameOnDocument' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’\\-]*$/u"],   // ’: phones type D’Souza
            'front' => ['required', ...$file],
            'back' => ['nullable', ...$file],
            'consent' => ['accepted'],
        ], [
            'nameOnDocument.regex' => __('Use letters, spaces, dots and hyphens only — as printed on the document.'),
            'consent.accepted' => __('Please confirm the document is yours and agree to its use for verification.'),
        ], [
            'docType' => __('document type'), 'number' => __('document number'), 'nameOnDocument' => __('name on the document'),
            'front' => __('front of the document'), 'back' => __('back of the document'),
        ])->validate();

        return VerificationDocType::from($data->docType);
    }

    /**
     * Sniff, clean and hash one upload. sha256 is of the file AS SENT (the duplicate-document flag).
     *
     * @return array{bytes: string, mime: string, sha256: string}
     *
     * @throws ValidationException
     */
    private function prepare(UploadedFile $file, string $field): array
    {
        $path = (string) $file->getRealPath();
        $original = (string) file_get_contents($path);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($original);
        $refuse = ValidationException::withMessages([$field => __('Please upload a JPG, PNG or PDF of your document.')]);

        if (! in_array($mime, self::MIMES, true)) {
            throw $refuse;
        }

        if ($mime === 'application/pdf') {
            if (! str_starts_with($original, '%PDF-')) {
                throw $refuse;
            }

            // Password-protected PDFs can't be reviewed; active content has no place in an ID scan.
            foreach (['/Encrypt', '/JavaScript', '/Launch', '/EmbeddedFile'] as $marker) {
                if (str_contains($original, $marker)) {
                    throw ValidationException::withMessages([$field => __('This PDF is password-protected or contains active content. Please upload a plain scan or a photo.')]);
                }
            }

            return ['bytes' => $original, 'mime' => $mime, 'sha256' => hash('sha256', $original)];
        }

        try {
            $clean = $this->sanitizer->sanitize($path);
        } catch (InvalidArgumentException) {
            throw $refuse;
        }

        try {
            if ($clean['width'] < 300 || $clean['height'] < 200) {
                throw ValidationException::withMessages([$field => __('This photo is too small to read. Please upload a sharper one.')]);
            }

            return ['bytes' => (string) file_get_contents($clean['path']), 'mime' => 'image/jpeg', 'sha256' => hash('sha256', $original)];
        } finally {
            @unlink($clean['path']);
        }
    }

    /**
     * @param  array<string, array{bytes: string, mime: string, sha256: string}>  $prepared
     * @param  array<string, string>  $stored
     *
     * @throws ValidationException
     */
    private function create(User $actor, Profile $profile, VerificationDocType $type, DocumentNumber $number, string $name, array $prepared, array $stored): VerificationRequest
    {
        // Lock the profile: two parallel submissions can't both pass the "one open request" rule.
        $locked = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

        if ($locked->is_verified) {
            throw ValidationException::withMessages(['front' => __('Your profile is already verified.')]);
        }

        if (VerificationRequest::query()->where('profile_id', $locked->id)->open()->exists()) {
            throw ValidationException::withMessages(['front' => __('Your document is already being checked. We’ll let you know as soon as it is done.')]);
        }

        $request = new VerificationRequest;
        $request->forceFill([
            'profile_id' => $locked->id,
            'doc_type' => $type,
            'number_last4' => $number->last4(),
            'number_hash' => $number->hash(),
            'name_on_document' => $name,
            'status' => VerificationStatus::Pending,
            'flags' => $this->flags->forSubmission($locked, $number->hash(), array_column($prepared, 'sha256'), $name) ?: null,
            'is_priority' => $locked->is_premium,   // A05 "paid-first ordering"
            'submitted_at' => now(),
            'consented_at' => now(),
        ])->save();

        foreach ($prepared as $side => $file) {
            $document = new VerificationDocument;
            $document->forceFill([
                'verification_request_id' => $request->id,
                'side' => DocumentSide::from($side),
                'disk' => $this->vault->disk(),
                'path' => $stored[$side],
                'mime' => $file['mime'],
                'size_bytes' => strlen($file['bytes']),
                'sha256' => $file['sha256'],
            ])->save();
        }

        $this->audit->record('verification.submitted', $request, after: ['doc_type' => $type->value, 'sides' => array_keys($prepared)],
            actor: $actor, subjectLabel: $locked->code);
        $this->queue->announceCount();

        return $request;
    }
}
