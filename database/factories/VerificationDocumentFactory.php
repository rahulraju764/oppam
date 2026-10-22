<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentSide;
use App\Models\VerificationDocument;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only — the file is NOT written. Tests that stream or purge a document store real bytes
 * through the DocumentVault (tests/Support/verification.php).
 *
 * @extends Factory<VerificationDocument>
 */
final class VerificationDocumentFactory extends Factory
{
    protected $model = VerificationDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'verification_request_id' => VerificationRequest::factory(),
            'side' => DocumentSide::Front,
            'disk' => (string) config('oppam.verification.disk'),
            'path' => 'verification/'.Str::lower((string) Str::ulid()),
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', Str::random(32)),
        ];
    }
}
