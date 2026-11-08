<?php

declare(strict_types=1);

namespace App\Data\Content;

use Illuminate\Http\UploadedFile;

/** A member's M16 success-story submission, already validated by the form (P8.2c). */
final readonly class MemberStoryData
{
    public function __construct(
        public string $partnerCode,
        public string $weddingDate,
        public ?string $place,
        public string $quote,
        public string $body,
        public ?UploadedFile $photo = null,
    ) {}
}
