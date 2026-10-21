<?php

declare(strict_types=1);

namespace App\Data\Chat;

use App\Enums\SafetyAction;
use App\Enums\SafetyRule;
use Carbon\CarbonImmutable;

/**
 * One chat safety rule as ContentSafety applies it (PRD A13): the default from SafetyRule or the A13
 * rules editor's override — pattern body (null for PROFANITY / REPETITION), the action in a thread's
 * first messages and later, and who last changed it.
 */
final readonly class SafetyRuleData
{
    public function __construct(
        public SafetyRule $rule,
        public ?string $pattern,
        public SafetyAction $early,
        public SafetyAction $later,
        public bool $customised = false,
        public ?CarbonImmutable $updatedAt = null,
        public ?string $updatedBy = null,
    ) {}

    public static function default(SafetyRule $rule): self
    {
        return new self($rule, $rule->defaultPattern(), $rule->defaultAction(true), $rule->defaultAction(false));
    }

    public function action(bool $early): SafetyAction
    {
        return $early ? $this->early : $this->later;
    }

    /** The full PCRE pattern (case-insensitive, Unicode), or null when the rule has none. */
    public function regex(): ?string
    {
        return $this->pattern === null ? null : self::compile($this->pattern);
    }

    public static function compile(string $pattern): string
    {
        return '/'.$pattern.'/iu';
    }
}
