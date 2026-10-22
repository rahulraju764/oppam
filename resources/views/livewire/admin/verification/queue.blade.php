<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('ID verification') }}</h1>
            <p>{{ __('Paid members first, then oldest first. Opening a request reserves it for you for :minutes minutes. Amber after :warn h, red after :breach h.', [
                'minutes' => config('oppam.verification.claim_minutes'),
                'warn' => $warnHours,
                'breach' => $breachHours,
            ]) }}</p>
        </div>
    </div>

    @if (session('status'))
        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
    @endif

    <nav class="admin-tabs mb-3" aria-label="{{ __('Verification lists') }}">
        @foreach (\App\Livewire\Admin\Verification\Queue::LISTS as $key)
            <button type="button" class="admin-tab @if ($list === $key) active @endif" wire:click="show('{{ $key }}')" @if ($list === $key) aria-current="page" @endif>
                {{ match ($key) { 'waiting' => __('Waiting'), 'escalated' => __('Escalated'), default => __('Decided (90 days)') } }}
                @if ($key !== 'decided')
                    <span class="nav-count">{{ $counts[$key === 'waiting' ? 'PENDING' : 'ESCALATED'] ?? 0 }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    <div wire:loading.flex wire:target="show,gotoPage,nextPage,previousPage" class="mb-3" aria-hidden="true">
        <x-ui.skeleton shape="row" :count="3" class="w-100" />
    </div>

    <x-admin.table wire:loading.remove wire:target="show,gotoPage,nextPage,previousPage"
                   :headers="[__('Profile'), __('Document'), __('Checks'), $list === 'decided' ? __('Decided (IST)') : __('Waiting since (IST)'), $list === 'decided' ? __('Decision') : __('Reviewer'), '']"
                   :caption="__('ID verification requests')" :empty="$this->requests->isEmpty()">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-id-card-o" :title="$list === 'decided' ? __('No decisions yet') : __('All caught up')"
                              :message="$list === 'decided' ? __('Nothing was decided in the last 90 days.') : __('No ID documents are waiting.')" />
        </x-slot:emptyState>

        @foreach ($this->requests as $request)
            @php($level = $aging->level($request->submitted_at))
            <tr wire:key="vr-{{ $request->id }}" @class(['vf-row', 'is-'.$level => $request->status->isOpen()])>
                <td>
                    <strong>{{ $request->profile?->code }}</strong>
                    @if ($request->is_priority)<x-ui.badge variant="premium" icon="fa-diamond">{{ __('Paid') }}</x-ui.badge>@endif
                    <div class="small">{{ $request->profile?->fullName() }}
                        <span class="text-muted-brand">{{ $request->profile?->gender->label() }}, {{ $request->profile?->age() }}</span></div>
                </td>
                <td>{{ $request->doc_type->label() }}<div class="small text-muted-brand">{{ $request->maskedNumber() }}</div></td>
                <td>
                    @forelse ($request->flagCodes() as $flag)
                        <x-ui.badge :variant="$flag->isSerious() ? 'danger' : 'warning'" icon="fa-flag">{{ $flag->label() }}</x-ui.badge>
                    @empty
                        <span class="text-muted-brand small">{{ __('None') }}</span>
                    @endforelse
                </td>
                <td>
                    @if ($list === 'decided')
                        {{ $request->decided_at?->timezone($timezone)->format('d M, g:i a') }}
                    @else
                        {{ $request->submitted_at->timezone($timezone)->format('d M, g:i a') }}
                        <div><x-ui.badge :variant="$aging->variant($level)" icon="fa-clock-o">{{ $aging->label($level) }}</x-ui.badge></div>
                    @endif
                </td>
                <td>
                    @if ($list === 'decided')
                        <x-ui.badge :variant="$request->status->badgeVariant()">{{ $request->status->label() }}</x-ui.badge>
                        <div class="small text-muted-brand">{{ $request->decidedBy?->name }}</div>
                    @elseif ($request->claimed_until?->isFuture())
                        <x-ui.badge variant="warning" icon="fa-eye">{{ __('Being reviewed by :name', ['name' => $request->claimedBy?->name]) }}</x-ui.badge>
                    @endif
                </td>
                <td class="text-end">
                    @if ($request->profile)
                        <x-ui.button size="sm" :href="route('admin.verification.review', ['profile' => $request->profile->code])">{{ $list === 'decided' ? __('View') : __('Review') }}</x-ui.button>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{ $this->requests->links() }}
</div>
