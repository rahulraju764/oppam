{{--
    A13 chat safety rules (PRD A13 rules editor): each rule's pattern and what it does in a
    conversation's first messages and later; a dry-run tester (nothing is stored). Editing needs
    chat.rules.edit and a typed reason (audited).
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Chat safety rules') }}</h1>
            <p>{{ __('What happens to a chat message that contains contact details, links, money requests or abusive words.') }}</p>
        </div>
    </div>

    <x-admin.table :headers="[__('Rule'), __('First :count messages', ['count' => $firstMessages]), __('Later'), __('Pattern'), __('Last change'), '']" :caption="__('Chat safety rules')">
        @foreach ($rules as $key => $rule)
            <tr wire:key="rule-{{ $key }}">
                <th scope="row">
                    {{ $rule->rule->label() }}
                    <span class="d-block text-muted small">{{ __('Queue reason: :reason', ['reason' => $rule->rule->flagRule()->label()]) }}</span>
                </th>
                <td><x-ui.badge :variant="$rule->early->badgeVariant()">{{ $rule->early->label() }}</x-ui.badge></td>
                <td><x-ui.badge :variant="$rule->later->badgeVariant()">{{ $rule->later->label() }}</x-ui.badge></td>
                <td>
                    @if ($rule->pattern !== null)
                        <code class="admin-rule-pattern">{{ \Illuminate\Support\Str::limit($rule->pattern, 80) }}</code>
                    @elseif ($rule->rule === \App\Enums\SafetyRule::Profanity)
                        <span class="small">{{ __('Words from the A11 lists') }}</span>
                    @else
                        <span class="small">{{ __(':threads conversations within :minutes minutes, :length+ characters (A15 settings)', $repetition) }}</span>
                    @endif
                </td>
                <td class="small">
                    @if ($rule->customised)
                        {{ $rule->updatedBy ?? '—' }}@if ($rule->updatedAt) · {{ $rule->updatedAt->setTimezone(config('oppam.display_timezone'))->format('d M Y') }}@endif
                    @else
                        {{ __('Default') }}
                    @endif
                </td>
                <td class="text-end">
                    @if ($canEdit)
                        <x-ui.button size="sm" variant="outline" type="button" wire:click="edit('{{ $key }}')" :aria-label="__('Edit :rule', ['rule' => $rule->rule->label()])">{{ __('Edit') }}</x-ui.button>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    @if ($wordLists !== [])
        <p class="small mt-3">
            {{ __('Abusive word lists:') }}
            @foreach ($wordLists as $list)
                <a href="{{ $list['url'] }}" wire:navigate>{{ $list['label'] }}</a>@if (! $loop->last) · @endif
            @endforeach
        </p>
    @endif

    <h2 class="admin-section-title">{{ __('Try the rules') }}</h2>
    <div class="ui-card">
        <p class="small">{{ __('Type a sample message to see what the rules would do. Nothing is stored or sent. The repeated-message rule needs real messages and isn’t tried here.') }}</p>
        <div class="row g-3">
            <div class="col-lg-8">
                <x-ui.textarea :label="__('Sample message')" name="sample" id="ruleSample" wire:model="sample" rows="3" />
            </div>
            <div class="col-lg-4">
                <x-ui.select :label="__('Where in the conversation')" name="position" id="rulePosition" wire:model="position" :options="$positions" />
                <div class="mt-3">
                    <x-ui.button type="button" icon="fa-flask" wire:click="test" loading="test">{{ __('Try it') }}</x-ui.button>
                </div>
            </div>
        </div>

        @include('livewire.admin.chat.partials.rule-result')
    </div>

    @if ($canEdit)
        <x-ui.modal name="edit-rule" :title="$editingRule ? __('Edit: :rule', ['rule' => $editingRule->label()]) : __('Edit rule')">
            @if ($editingRule)
                @if ($editingRule->hasPattern())
                    <x-ui.textarea :label="__('Pattern (regular expression, case-insensitive)')" name="pattern" id="rulePattern" wire:model="pattern" rows="3"
                                   :hint="__('Write “/” as “\/”. A pattern that is slow on long messages or matches empty text is refused.')" class="admin-rule-pattern" />
                @endif
                <div class="row g-3 mt-1">
                    <div class="col-sm-6">
                        <x-ui.select :label="__('In the first :count messages', ['count' => $firstMessages])" name="early" id="ruleEarly" wire:model="early" :options="$actionOptions" />
                    </div>
                    <div class="col-sm-6">
                        <x-ui.select :label="__('Later in the conversation')" name="later" id="ruleLater" wire:model="later" :options="$actionOptions" />
                    </div>
                </div>
                <p class="small mt-2">{{ __('“Block the message” refuses it: nothing is stored and the member is asked to edit it.') }}</p>
                <div class="mt-3">
                    <x-ui.textarea :label="__('Sample message')" name="draftSample" id="ruleDraftSample" wire:model="sample" error="sample" rows="2" />
                    <x-ui.button size="sm" variant="outline" type="button" icon="fa-flask" wire:click="testDraft" loading="testDraft" class="mt-2">{{ __('Try this draft') }}</x-ui.button>
                    @include('livewire.admin.chat.partials.rule-result')
                </div>
                <div class="mt-3">
                    <x-ui.textarea :label="__('Reason (recorded in the audit log)')" name="ruleReason" id="ruleReason" wire:model="reason" error="reason" rows="2" required />
                </div>
            @endif

            <x-slot:footer>
                <x-ui.button variant="ghost" type="button" wire:click="resetRule" loading="resetRule">{{ __('Reset to default') }}</x-ui.button>
                <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" wire:click="save" loading="save">{{ __('Save rule') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
