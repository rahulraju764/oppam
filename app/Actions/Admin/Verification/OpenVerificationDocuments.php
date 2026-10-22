<?php

declare(strict_types=1);

namespace App\Actions\Admin\Verification;

use App\Domain\Verification\DocumentViewerAccess;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Open the in-browser document viewer (A05): `verification.document.view` — separate from
 * approve / reject — and, while the request waits, nobody else holding its claim. Grants THIS
 * admin's session 10 minutes and returns the signed links. Audited here (opened) and again for
 * every file the viewer fetches (VerificationDocumentController).
 */
final class OpenVerificationDocuments
{
    public function __construct(
        private readonly DocumentViewerAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, string> side slug => signed URL
     *
     * @throws AuthorizationException
     * @throws VerificationRequestUnavailable
     */
    public function handle(AdminUser $admin, VerificationRequest $request): array
    {
        Gate::forUser($admin)->authorize('verification.document.view');

        if ($request->documents_purged_at !== null) {
            throw VerificationRequestUnavailable::documentsPurged();
        }

        if (! $this->access->canView($admin, $request)) {
            throw VerificationRequestUnavailable::claimedByOther();
        }

        $request->loadMissing(['documents', 'profile:id,code']);
        $code = (string) $request->profile?->code;

        $this->audit->record('verification.documents_opened', $request,
            after: ['sides' => $request->documents->map(fn ($d): string => $d->side->value)->all(), 'minutes' => DocumentViewerAccess::minutes()],
            actor: $admin, subjectLabel: $code);

        return $this->access->grant($request, $code);
    }
}
