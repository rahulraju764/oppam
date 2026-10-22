<?php

declare(strict_types=1);

namespace App\Data\Verification;

use Illuminate\Http\UploadedFile;

/** What the member sent on /verify (M09). Validated by SubmitVerification, never trusted as is. */
final readonly class VerificationSubmissionData
{
    public function __construct(
        public string $docType,
        public string $number,
        public string $nameOnDocument,
        public ?UploadedFile $front,
        public ?UploadedFile $back,
        public bool $consent,
    ) {}
}
