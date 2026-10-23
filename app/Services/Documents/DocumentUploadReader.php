<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Services\Media\ImageSanitizer;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reads an uploaded identity document — ID (M09) or PAN card (§11A KYC) — the one safe way:
 * the bytes are sniffed (never the browser's word); JPG / PNG are re-encoded (EXIF / GPS dropped,
 * renamed scripts refused) and must be big enough to read; PDFs must be plain (no password,
 * JavaScript, launch actions or embedded files). The caller validates size and extension first
 * and encrypts the result into the DocumentVault.
 */
final class DocumentUploadReader
{
    public const MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(private readonly ImageSanitizer $sanitizer) {}

    /**
     * sha256 is of the file AS SENT (duplicate checks).
     *
     * @return array{bytes: string, mime: string, sha256: string}
     *
     * @throws ValidationException under $field
     */
    public function read(UploadedFile $file, string $field): array
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
}
