<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Data\Chat\SafetyRuleData;
use App\Enums\SafetyAction;
use App\Enums\SafetyRule;
use App\Models\ChatSafetyRule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The chat safety rules in force (PRD A13 rules editor): SafetyRule's defaults with the editor's
 * overrides (chat_safety_rules) on top, in SafetyRule order. Read on every chat send, so the
 * overrides are cached (plain arrays) until UpdateSafetyRule / ResetSafetyRule flush them, and
 * memoised for the request. The cache also expires after CACHE_SECONDS: a request that read the old
 * rows just before an edit committed could write them back after the flush — the TTL bounds that.
 */
final class ChatSafetyRules
{
    private const CACHE_KEY = 'chat.safety_rules.v1';

    private const CACHE_SECONDS = 600;

    /** @var array<string, SafetyRuleData>|null */
    private ?array $rules = null;

    public function __construct(private readonly Cache $cache) {}

    /** @return array<string, SafetyRuleData> by SafetyRule value, in SafetyRule order */
    public function all(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        /** @var array<string, array{pattern: string|null, early: string, later: string, updated_at: string|null, by: string|null}> $overrides */
        $overrides = $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => ChatSafetyRule::query()
            ->with('updatedBy:id,name')->get()
            ->mapWithKeys(fn (ChatSafetyRule $row): array => [$row->key => [
                'pattern' => $row->pattern,
                'early' => $row->early_action->value,
                'later' => $row->later_action->value,
                'updated_at' => $row->updated_at?->toIso8601String(),
                'by' => $row->updatedBy?->name,
            ]])->all());

        $rules = [];
        foreach (SafetyRule::cases() as $rule) {
            $row = $overrides[$rule->value] ?? null;
            $rules[$rule->value] = $row === null ? SafetyRuleData::default($rule) : new SafetyRuleData(
                rule: $rule,
                pattern: $rule->hasPattern() ? ($row['pattern'] ?? $rule->defaultPattern()) : null,
                early: SafetyAction::tryFrom($row['early']) ?? $rule->defaultAction(true),
                later: SafetyAction::tryFrom($row['later']) ?? $rule->defaultAction(false),
                customised: true,
                updatedAt: $row['updated_at'] !== null ? CarbonImmutable::parse($row['updated_at']) : null,
                updatedBy: $row['by'],
            );
        }

        return $this->rules = $rules;
    }

    public function get(SafetyRule $rule): SafetyRuleData
    {
        return $this->all()[$rule->value];
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->rules = null;
    }
}
