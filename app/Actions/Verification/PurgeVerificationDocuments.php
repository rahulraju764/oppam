<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Contracts\DocumentVault;
use App\Models\VerificationDocument;
use App\Models\VerificationRequest;
use Illuminate\Support\Facades\Log;

/**
 * Delete a request's ID files and PROVE they are gone (M09 "originals deleted 90 days after
 * decision", A05 "daily purge job verified"). Each file is deleted, then looked up again: only a
 * file the disk no longer has is marked purged (path and hash cleared). If any file survives, the
 * request keeps `documents_purged_at` empty and the next run tries again; the failure is logged
 * without any personal data. What stays: the decision, the masked number and its keyed hash.
 */
final class PurgeVerificationDocuments
{
    public function __construct(private readonly DocumentVault $vault) {}

    /** @return bool whether every file of the request is now verifiably deleted */
    public function handle(VerificationRequest $request): bool
    {
        $complete = true;

        $request->documents()->whereNull('purged_at')->get()->each(function (VerificationDocument $document) use (&$complete): void {
            if ($document->path !== null) {
                rescue(fn () => $this->vault->delete((string) $document->path), report: true);

                if ($this->vault->exists((string) $document->path)) {
                    Log::error('verification.purge_failed', ['document' => $document->id]);
                    $complete = false;

                    return;
                }
            }

            $document->forceFill(['purged_at' => now(), 'path' => null, 'sha256' => null])->save();
        });

        if ($complete) {
            $request->forceFill(['documents_purged_at' => now(), 'name_on_document' => null])->save();
        }

        return $complete;
    }
}
