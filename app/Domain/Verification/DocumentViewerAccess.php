<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Enums\DocumentSide;
use App\Models\AdminUser;
use App\Models\VerificationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\URL;

/**
 * Who may see an ID document, and the link they get (PRD A05: "verification.document.view
 * separate from approve/reject; 10-minute signed URLs"). OpenVerificationDocuments grants a viewer
 * to THIS admin's session for one request; the signed link names only the profile code and the
 * side (URLs use codes) and works for 10 minutes, and only in the session that was granted —
 * another admin holding a copied link gets a 404. VerificationDocumentController checks again and
 * audits every fetch.
 */
final class DocumentViewerAccess
{
    private const SESSION_PREFIX = 'verification.viewer.';

    public function __construct(private readonly Session $session) {}

    /**
     * May this admin see this request's documents at all? Needs the permission, files not yet
     * purged, and — while the request waits — nobody else holding its claim.
     */
    public function canView(AdminUser $admin, VerificationRequest $request): bool
    {
        return $admin->can('verification.document.view')
            && $request->documents_purged_at === null
            && ! ($request->status->isOpen() && $request->isClaimedByOther($admin));
    }

    /**
     * Remember the grant in this session and build the signed links, by side.
     *
     * @return array<string, string> side slug => signed URL
     */
    public function grant(VerificationRequest $request, string $profileCode): array
    {
        $this->session->put(self::SESSION_PREFIX.$profileCode, ['request' => $request->id, 'at' => now()->getTimestamp()]);
        $expires = $this->expiresAt();

        return $request->documents
            ->filter(fn ($document): bool => ! $document->isPurged())
            ->mapWithKeys(fn ($document): array => [$document->side->slug() => URL::temporarySignedRoute(
                'admin.verification.document', $expires, ['profile' => $profileCode, 'side' => $document->side->slug()],
            )])->all();
    }

    /** The request this session was granted for the profile, while the grant is still fresh. */
    public function grantedRequestId(string $profileCode): ?string
    {
        $grant = $this->session->get(self::SESSION_PREFIX.$profileCode);

        if (! is_array($grant) || ! is_string($grant['request'] ?? null)) {
            return null;
        }

        $fresh = (int) ($grant['at'] ?? 0) >= now()->subMinutes(self::minutes())->getTimestamp();

        return $fresh ? $grant['request'] : null;
    }

    public function forget(string $profileCode): void
    {
        $this->session->forget(self::SESSION_PREFIX.$profileCode);
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(self::minutes());
    }

    public static function minutes(): int
    {
        return (int) config('oppam.verification.link_minutes');
    }

    public static function side(string $slug): ?DocumentSide
    {
        return DocumentSide::fromSlug($slug);
    }
}
