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
use App\Services\Documents\DocumentUploadReader;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Send an ID for verification (PRD M09, F05): document type, number, the name as printed, front
 * (and optionally back) as JPG / PNG / PDF ≤ 5 MB, and consent. Rules:
 *  - the member's own active account, never in an admin support session (A01: no documents on a
 *    member's behalf); one open request at a time; not while already verified;
 *  - the number must have the document's shape (Aadhaar: 12 digits + Verhoeff check) — only its
 *    last 4 and a keyed hash are kept (DocumentNumber);
 *  - files are read by DocumentUploadReader: sniffed, images re-encoded, PDFs plain;
 *  - files are encrypted into the DocumentVault before the row exists, removed again if it fails;
 *  - fraud flags are computed once (VerificationFlags); the A05 badge goes up live;
 *  - `verification.max_submissions_per_day` per member (rule 10).
 */
final class SubmitVerification
{
    public function __construct(
        private readonly DocumentVault $vault,
        private readonly DocumentUploadReader $reader,
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
            $prepared[$side] = $this->reader->read($file, $side === DocumentSide::Front->value ? 'front' : 'back');
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
        $file = ['file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:'.implode(',', DocumentUploadReader::MIMES), 'max:'.(int) config('oppam.verification.max_kb')];

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
