<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Domain\Chat\ChatSafetyRules;
use App\Domain\Chat\PatternGuard;
use App\Enums\SafetyAction;
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
 * Change one chat safety rule in the A13 rules editor (PRD A13 "patterns for phone / email / UPI,
 * per-rule action (mask / flag / block)"): its pattern (rules that have one) and its action in a
 * thread's first messages and later. Needs `chat.rules.edit` and a typed reason (5 – 500). The
 * pattern must pass PatternGuard (compiles, never matches empty text, no catastrophic backtracking);
 * each action must be one the rule allows. Audited ("chat.rule.updated", before / after); every chat
 * send uses the new rule from the next request (ChatSafetyRules cache flushed after commit).
 */
final class UpdateSafetyRule
{
    public function __construct(
        private readonly ChatSafetyRules $rules,
        private readonly PatternGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, SafetyRule $rule, ?string $pattern, SafetyAction $early, SafetyAction $later, string $reason): void
    {
        Gate::forUser($admin)->authorize('chat.rules.edit');

        $reason = trim($reason);
        $pattern = $rule->hasPattern() ? trim((string) $pattern) : null;
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']], [], ['reason' => __('reason')])->validate();

        $allowed = $rule->allowedActions();
        $errors = [];
        if (! in_array($early, $allowed, true)) {
            $errors['early'] = __('This rule can’t do that.');
        }
        if (! in_array($later, $allowed, true)) {
            $errors['later'] = __('This rule can’t do that.');
        }
        $problem = $pattern === null ? null : $this->guard->problem($pattern);
        if ($problem !== null) {
            $errors['pattern'] = $problem;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($admin, $rule, $pattern, $early, $later, $reason): void {
            $before = $this->rules->get($rule);
            $row = ChatSafetyRule::query()->lockForUpdate()->find($rule->value) ?? (new ChatSafetyRule)->forceFill(['key' => $rule->value]);
            $row->pattern = $pattern;
            $row->early_action = $early;
            $row->later_action = $later;
            $row->updated_by_admin_id = $admin->id;
            $row->save();

            $this->audit->record('chat.rule.updated', null,
                ['pattern' => $before->pattern, 'early' => $before->early->value, 'later' => $before->later->value],
                ['pattern' => $pattern, 'early' => $early->value, 'later' => $later->value],
                $reason, $admin, $rule->value);
        });

        DB::afterCommit(fn () => $this->rules->flush());
    }
}
