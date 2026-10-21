<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Domain\Chat\ChatSafetyRules;
use App\Enums\SafetyRule;
use App\Models\AdminUser;
use App\Models\ChatSafetyRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Put one chat safety rule back to its default pattern and actions (A13 rules editor) — the override
 * row is deleted. Needs `chat.rules.edit` and a typed reason; audited ("chat.rule.reset"). Nothing
 * happens when the rule already is the default.
 */
final class ResetSafetyRule
{
    public function __construct(
        private readonly ChatSafetyRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool whether the rule changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, SafetyRule $rule, string $reason): bool
    {
        Gate::forUser($admin)->authorize('chat.rules.edit');
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']], [], ['reason' => __('reason')])->validate();

        $changed = DB::transaction(function () use ($admin, $rule, $reason): bool {
            $row = ChatSafetyRule::query()->lockForUpdate()->find($rule->value);
            if ($row === null) {
                return false;
            }

            $before = ['pattern' => $row->pattern, 'early' => $row->early_action->value, 'later' => $row->later_action->value];
            $row->delete();
            $this->audit->record('chat.rule.reset', null, $before,
                ['pattern' => $rule->defaultPattern(), 'early' => $rule->defaultAction(true)->value, 'later' => $rule->defaultAction(false)->value],
                $reason, $admin, $rule->value);

            return true;
        });

        DB::afterCommit(fn () => $this->rules->flush());

        return $changed;
    }
}
