<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Domain\Broker\KycDocumentAccess;
use App\Models\AdminUser;
use App\Models\BrokerKyc;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Open the PAN image of a KYC submission in the A07 viewer (B.12, B.18): `brokers.kyc`, audited
 * here (opened) and again for every fetch (BrokerKycDocumentController). Returns the signed link.
 */
final class OpenBrokerPanImage
{
    public function __construct(
        private readonly KycDocumentAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException when there is no image
     */
    public function handle(AdminUser $admin, BrokerKyc $kyc): string
    {
        Gate::forUser($admin)->authorize('brokers.kyc');

        if ($kyc->pan_image_path === null) {
            throw ValidationException::withMessages(['reason' => __('This submission has no PAN image.')]);
        }

        $kyc->loadMissing('broker:id,code');
        $code = (string) $kyc->broker?->code;

        $this->audit->record('brokers.kyc_pan_opened', $kyc, after: ['minutes' => KycDocumentAccess::minutes()], actor: $admin, subjectLabel: $code);

        return $this->access->grant($kyc, $code);
    }
}
