<?php

declare(strict_types=1);

namespace App\Data\Broker;

use App\ValueObjects\PhoneNumber;

/** A bureau an admin creates in A07 (§11A B.12 "create broker"), validated by Admin\Brokers\BureauForm. */
final readonly class NewBureauData
{
    public function __construct(
        public string $name,
        public ?string $bureauName,
        public PhoneNumber $phone,
        public ?string $email,
        public int $districtId,
    ) {}
}
