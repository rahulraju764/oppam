<?php

declare(strict_types=1);

namespace App\Data\Chat;

use App\Enums\SafetyAction;
use App\Enums\SafetyRule;

/**
 * One rule that matched a message, with what it did and what it matched — for the A13 dry-run tester
 * (the A13 queue keeps only the encrypted excerpt of a real message).
 */
final readonly class SafetyHitData
{
    /** @param  list<string>  $matches */
    public function __construct(
        public SafetyRule $rule,
        public SafetyAction $action,
        public array $matches,
    ) {}
}
