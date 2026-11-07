<?php

declare(strict_types=1);

namespace App\Data\Content;

use App\Enums\LegalKind;

/** What a member still has to agree to: kinds behind the blocking page, and kinds with a notice only. */
final readonly class PendingLegalData
{
    /**
     * @param  list<LegalKind>  $required
     * @param  list<LegalKind>  $notices
     */
    public function __construct(
        public array $required,
        public array $notices,
    ) {}

    public function blocks(): bool
    {
        return $this->required !== [];
    }
}
