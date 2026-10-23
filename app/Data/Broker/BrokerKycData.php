<?php

declare(strict_types=1);

namespace App\Data\Broker;

use Illuminate\Http\UploadedFile;
use SensitiveParameter;

/** What the bureau Owner sent on /broker/kyc (§11A B.3). Validated by SubmitBrokerKyc. */
final readonly class BrokerKycData
{
    public function __construct(
        #[SensitiveParameter] public string $pan,
        public ?UploadedFile $panImage,
        #[SensitiveParameter] public string $accountNumber,
        public string $ifsc,
        public string $accountName,
    ) {}
}
