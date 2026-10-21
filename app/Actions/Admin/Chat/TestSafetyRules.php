<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Contracts\ContentSafety;
use App\Data\Chat\SafetyRuleData;
use App\Data\Chat\SafetyScanData;
use App\Domain\Chat\PatternGuard;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The A13 rules editor's dry-run tester (PRD A13 "dry-run tester against sample text"): what the
 * rules in force — or with one rule replaced by the draft being edited — would do to a sample message
 * at the start of a thread or later. Read only: nothing is stored, logged or audited (the sample is
 * the moderator's own text, never a member's). Needs `chat.view_flagged`; a draft pattern must pass
 * PatternGuard first, so a slow pattern is refused before it runs.
 */
final class TestSafetyRules
{
    public function __construct(
        private readonly ContentSafety $safety,
        private readonly PatternGuard $guard,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, string $sample, bool $early, ?SafetyRuleData $draft = null): SafetyScanData
    {
        Gate::forUser($admin)->authorize('chat.view_flagged');

        $sample = trim($sample);
        Validator::make(['sample' => $sample], ['sample' => ['required', 'string', 'max:'.Settings::int(SettingKey::ChatMaxLength)]],
            [], ['sample' => __('sample text')])->validate();

        $problem = $draft?->pattern === null ? null : $this->guard->problem($draft->pattern);
        if ($problem !== null) {
            throw ValidationException::withMessages(['pattern' => $problem]);
        }

        return $this->safety->dryRun($sample, $early, $draft);
    }
}
