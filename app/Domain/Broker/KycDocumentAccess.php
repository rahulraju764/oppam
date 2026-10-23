<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Models\BrokerKyc;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\URL;

/**
 * The A07 PAN-image viewer link (PRD §11A B.12 "view PAN (signed URL, audited)", B.18): opening it
 * grants THIS admin session `oppam.broker.kyc_link_minutes` for one KYC submission; the signed link
 * names only the bureau code (URLs use codes) and works only in the granted session — a copied link
 * is a 404. BrokerKycDocumentController re-checks and audits every fetch (same model as the A05
 * ID viewer, DocumentViewerAccess).
 */
final class KycDocumentAccess
{
    private const SESSION_PREFIX = 'brokers.kyc.viewer.';

    public function __construct(private readonly Session $session) {}

    public function grant(BrokerKyc $kyc, string $brokerCode): string
    {
        $this->session->put(self::SESSION_PREFIX.$brokerCode, ['kyc' => $kyc->id, 'at' => now()->getTimestamp()]);

        return URL::temporarySignedRoute('admin.brokers.kyc.pan', $this->expiresAt(), ['broker' => $brokerCode]);
    }

    public function grantedKycId(string $brokerCode): ?string
    {
        $grant = $this->session->get(self::SESSION_PREFIX.$brokerCode);

        if (! is_array($grant) || ! is_string($grant['kyc'] ?? null)) {
            return null;
        }

        return (int) ($grant['at'] ?? 0) >= now()->subMinutes(self::minutes())->getTimestamp() ? $grant['kyc'] : null;
    }

    public function forget(string $brokerCode): void
    {
        $this->session->forget(self::SESSION_PREFIX.$brokerCode);
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(self::minutes());
    }

    public static function minutes(): int
    {
        return (int) config('oppam.broker.kyc_link_minutes');
    }
}
