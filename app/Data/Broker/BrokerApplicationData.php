<?php

declare(strict_types=1);

namespace App\Data\Broker;

use App\ValueObjects\PhoneNumber;
use SensitiveParameter;

/** A validated /broker/apply submission (§11A B.11). The PAN is normalised (upper case, no spaces). */
final readonly class BrokerApplicationData
{
    public function __construct(
        public string $name,
        public ?string $bureauName,
        public PhoneNumber $phone,
        public ?string $email,
        public int $districtId,
        #[SensitiveParameter] public string $pan,
        #[SensitiveParameter] public string $password,
    ) {}
}
