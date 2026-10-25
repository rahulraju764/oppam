<?php

declare(strict_types=1);

namespace App\Data\Broker;

/**
 * What the bureau form adds to the M02 sections (§11A B.6 / B.17): the client's mobile (the
 * future claim key, as typed — normalised to E.164 by the Action) and the consent confirmation.
 */
final readonly class ManagedProfileData
{
    public function __construct(
        public string $clientPhone,
        public bool $consent,
    ) {}
}
