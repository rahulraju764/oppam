{{--
    A10 abuse cases. Metadata only — no report text or messages on this page.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Safety cases') }}</h1>
            <p>{{ __('All reports about one member are grouped into one case. Most urgent first, then the one whose deadline is nearest.') }}</p>
        </div>
    </div>

    <div class="row g-3 mb-3">
        @foreach (\App\Enums\CasePriority::cases() as $p)
            <div class="col-4">
                <div class="ui-card case-count">
                    <x-ui.badge :variant="$p->badgeVariant()">{{ $p->label() }}</x-ui.badge>
                    <strong class="case-count__n">{{ $counts[$p->value] ?? 0 }}</strong>
                    <span class="small text-muted-brand">{{ __('open · :hours h to act', ['hours' => $p->slaHours()]) }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
        <nav class="admin-tabs" aria-label="{{ __('Case lists') }}">
            @foreach (\App\Livewire\Admin\Safety\CaseQueue::LISTS as $key)
                <button type="button" class="admin-tab @if ($list === $key) active @endif" wire:click="show('{{ $key }}')" @if ($list === $key) aria-current="page" @endif>
                    {{ match ($key) { 'open' => __('Open'), 'mine' => __('Assigned to me'), default => __('Decided (90 days)') } }}
                </button>
            @endforeach
        </nav>
        <div class="case-filter">
            <x-ui.select :label="__('Priority')" name="priority" id="casePriority" wire:model.live="priority" :options="$priorities" :placeholder="__('All priorities')" />
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="show, priority, gotoPage, nextPage, previousPage">
        <x-admin.table :headers="[__('Case'), __('Member'), __('Priority'), __('Reports'), $list === 'decided' ? __('Decided (IST)') : __('Act by (IST)'), $list === 'decided' ? __('Outcome') : __('Assigned'), '']"
                       :caption="__('Safety cases')" :empty="$this->cases->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-shield" :title="$list === 'decided' ? __('No decisions yet') : __('All caught up')"
                                  :message="$list === 'decided' ? __('No case was decided in the last 90 days.') : __('No open safety cases.')" />
            </x-slot:emptyState>

            @foreach ($this->cases as $case)
                <tr wire:key="case-{{ $case->code }}" @class(['is-breach' => $case->isOverdue()])>
                    <td><strong>{{ $case->code }}</strong>
                        @if ($case->escalated_at)<x-ui.badge variant="danger" icon="fa-level-up">{{ __('Escalated') }}</x-ui.badge>@endif
                    </td>
                    <td>{{ $case->reported?->code }}<div class="small text-muted-brand">{{ $case->reported?->fullName() }}</div></td>
                    <td><x-ui.badge :variant="$case->priority->badgeVariant()">{{ $case->priority->value }}</x-ui.badge></td>
                    <td>{{ trans_choice(':count report|:count reports', $case->reports_count, ['count' => $case->reports_count]) }}
                        <div class="small text-muted-brand">{{ trans_choice(':count member|:count members', $case->reporters_count, ['count' => $case->reporters_count]) }}</div></td>
                    <td>
                        @if ($list === 'decided')
                            {{ $case->decided_at?->timezone($timezone)->format('d M, g:i a') }}
                        @else
                            {{ $case->sla_due_at->timezone($timezone)->format('d M, g:i a') }}
                            @if ($case->isOverdue())<div><x-ui.badge variant="danger" icon="fa-clock-o">{{ __('Overdue') }}</x-ui.badge></div>@endif
                        @endif
                    </td>
                    <td>
                        @if ($list === 'decided')
                            @if ($case->outcome)<x-ui.badge :variant="$case->outcome->badgeVariant()">{{ $case->outcome->label() }}</x-ui.badge>@endif
                            <div class="small text-muted-brand">{{ $case->decidedBy?->name }}</div>
                        @else
                            {{ $case->assignee?->name ?? '—' }}
                        @endif
                    </td>
                    <td class="text-end">
                        <x-ui.button size="sm" :href="route('admin.safety.cases.show', ['case' => $case->code])" wire:navigate>{{ $list === 'decided' ? __('View') : __('Review') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $this->cases->links() }}
    </div>
</div>
