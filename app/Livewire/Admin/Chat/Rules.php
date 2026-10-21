<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Chat;

use App\Actions\Admin\Chat\ResetSafetyRule;
use App\Actions\Admin\Chat\TestSafetyRules;
use App\Actions\Admin\Chat\UpdateSafetyRule;
use App\Data\Chat\SafetyRuleData;
use App\Data\Chat\SafetyScanData;
use App\Domain\Chat\ChatSafetyRules;
use App\Domain\Masters\MasterLists;
use App\Enums\SafetyAction;
use App\Enums\SafetyRule;
use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A13 chat safety rules (PRD A13 "Wordlist & rules editor (links to A11 lists): patterns for phone /
 * email / UPI, per-rule action (mask / flag / block), dry-run tester against sample text"). Everyone
 * with `chat.view_flagged` sees the rules and may use the tester; editing / resetting needs
 * `chat.rules.edit` (UpdateSafetyRule / ResetSafetyRule re-check it, with a typed reason). The tester
 * stores nothing; "Try this draft" tests the dialog's values before they are saved. The abusive
 * words themselves are the A11 lists; the repetition thresholds are A15 settings (both linked).
 */
#[Layout('layouts::admin', ['title' => 'Chat safety rules'])]
final class Rules extends Component
{
    /** The rule open in the edit dialog (a SafetyRule value; re-validated on save). */
    public string $editing = '';

    public string $pattern = '';

    public string $early = '';

    public string $later = '';

    public string $reason = '';

    public string $sample = '';

    public string $position = 'early';

    /** @var array{text: string, blocked: string|null, hits: list<array{rule: string, action: string, matches: string}>, flags: list<string>}|null */
    public ?array $result = null;

    public function mount(): void
    {
        Gate::authorize('chat.view_flagged');
    }

    public function edit(string $key, ChatSafetyRules $rules): void
    {
        $rule = SafetyRule::tryFrom($key) ?? abort(404);
        $data = $rules->get($rule);
        $this->editing = $rule->value;
        $this->pattern = (string) $data->pattern;
        $this->early = $data->early->value;
        $this->later = $data->later->value;
        $this->reset('reason');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-rule');
    }

    public function save(UpdateSafetyRule $update): void
    {
        [$rule, $early, $later] = $this->draftParts();

        try {
            $update->handle($this->admin(), $rule, $rule->hasPattern() ? $this->pattern : null, $early, $later, $this->reason);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        }

        $this->dispatch('close-modal', name: 'edit-rule');
        $this->dispatch('toast', type: 'success', title: __('Rule saved. New messages use it from now on.'));
    }

    public function resetRule(ResetSafetyRule $reset): void
    {
        $rule = SafetyRule::tryFrom($this->editing) ?? abort(404);

        try {
            $changed = $reset->handle($this->admin(), $rule, $this->reason);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        }

        $this->dispatch('close-modal', name: 'edit-rule');
        $this->dispatch('toast', type: 'success', title: $changed ? __('Rule set back to its default.') : __('This rule already uses its default.'));
    }

    /** Dry run with the rules in force. */
    public function test(TestSafetyRules $tester): void
    {
        $this->result = $this->present($tester->handle($this->admin(), $this->sample, $this->position === 'early'));
    }

    /** Dry run with the dialog's draft in place of the saved rule. */
    public function testDraft(TestSafetyRules $tester): void
    {
        [$rule, $early, $later] = $this->draftParts();
        $draft = new SafetyRuleData($rule, $rule->hasPattern() ? trim($this->pattern) : null, $early, $later);
        $this->result = $this->present($tester->handle($this->admin(), $this->sample, $this->position === 'early', $draft));
    }

    public function render(ChatSafetyRules $rules): View
    {
        $admin = $this->admin();
        $editing = SafetyRule::tryFrom($this->editing);

        return view('livewire.admin.chat.rules', [
            'rules' => $rules->all(),
            'canEdit' => $admin->can('chat.rules.edit'),
            'editingRule' => $editing,
            'actionOptions' => $editing === null ? [] : collect($editing->allowedActions())
                ->mapWithKeys(fn (SafetyAction $a): array => [$a->value => $a->label()])->all(),
            'positions' => ['early' => __('In the first :count messages of a conversation', ['count' => Settings::int(SettingKey::ChatSafetyFirstMessages)]), 'later' => __('Later in the conversation')],
            'firstMessages' => Settings::int(SettingKey::ChatSafetyFirstMessages),
            'wordLists' => $admin->can('masters.view') ? collect(MasterLists::WORD_LISTS)
                ->map(fn (string $label, string $group): array => ['label' => $label, 'url' => route('admin.masters.edit', ['list' => str_replace('_', '-', $group)])])->values()->all() : [],
            'repetition' => [
                'threads' => Settings::int(SettingKey::ChatSpamRepeatThreads),
                'minutes' => Settings::int(SettingKey::ChatSpamRepeatWindowMinutes),
                'length' => Settings::int(SettingKey::ChatSpamRepeatMinLength),
            ],
        ]);
    }

    /** @return array{0: SafetyRule, 1: SafetyAction, 2: SafetyAction} */
    private function draftParts(): array
    {
        $rule = SafetyRule::tryFrom($this->editing) ?? abort(404);
        $early = SafetyAction::tryFrom($this->early);
        $later = SafetyAction::tryFrom($this->later);
        if ($early === null || $later === null) {
            throw ValidationException::withMessages(array_filter([
                'early' => $early === null ? __('Choose what the rule does.') : null,
                'later' => $later === null ? __('Choose what the rule does.') : null,
            ]));
        }

        return [$rule, $early, $later];
    }

    /** @return array{text: string, blocked: string|null, hits: list<array{rule: string, action: string, matches: string}>, flags: list<string>} */
    private function present(SafetyScanData $scan): array
    {
        return [
            'text' => $scan->body,
            'blocked' => $scan->blockedBy?->refusal(),
            'hits' => array_map(fn ($hit): array => [
                'rule' => $hit->rule->label(),
                'action' => $hit->action->label(),
                'matches' => implode(', ', array_unique($hit->matches)),
            ], $scan->hits),
            'flags' => array_map(fn ($finding): string => $finding->rule->label(), $scan->findings),
        ];
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
