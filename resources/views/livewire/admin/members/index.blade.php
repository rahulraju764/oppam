<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Members') }}</h1>
            <p>{{ __('Search by member code, email, phone or name. Every change is written to the audit log.') }}</p>
        </div>
        @can('members.export')
            <x-ui.button type="button" variant="outline" icon="fa-download" wire:click="export" loading="export">{{ __('Export CSV') }}</x-ui.button>
        @endcan
    </div>

    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <x-ui.input :label="__('Search')" name="q" type="search" wire:model.live.debounce.400ms="q" :hint="__('OPM code, email, phone (any 4+ digits) or name')" />
            </div>
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Account')" name="status" wire:model.live="status" :options="$statuses" :placeholder="__('Any (not deleted)')" />
            </div>
            <div class="col-6 col-lg-3">
                <x-ui.select :label="__('Profile')" name="profileStatus" wire:model.live="profileStatus" :options="$profileStatuses" :placeholder="__('Any')" />
            </div>
        </div>

        <details class="mt-3 admin-filters" @if ($plan.$verified.$completeness.$gender.$religion.$district.$from.$to.$active !== '') open @endif>
            <summary>{{ __('More filters') }}</summary>
            <div class="row g-3 mt-1">
                <div class="col-6 col-lg-3"><x-ui.select :label="__('Plan')" name="plan" wire:model.live="plan" :options="$plans" :placeholder="__('Any')" /></div>
                <div class="col-6 col-lg-3"><x-ui.select :label="__('ID verified')" name="verified" wire:model.live="verified" :options="['1' => __('Yes'), '0' => __('No')]" :placeholder="__('Any')" /></div>
                <div class="col-6 col-lg-3"><x-ui.select :label="__('Completeness')" name="completeness" wire:model.live="completeness" :options="['25' => '25%+', '50' => '50%+', '75' => '75%+', '100' => '100%']" :placeholder="__('Any')" /></div>
                <div class="col-6 col-lg-3"><x-ui.select :label="__('Gender')" name="gender" wire:model.live="gender" :options="$genders" :placeholder="__('Any')" /></div>
                <div class="col-6 col-lg-3"><x-ui.select :label="__('Religion')" name="religion" wire:model.live="religion" :options="$religions" :placeholder="__('Any')" /></div>
                <div class="col-6 col-lg-3"><x-ui.select :label="__('District')" name="district" wire:model.live="district" :options="$districts" :placeholder="__('Any')" /></div>
                <div class="col-6 col-lg-3"><x-ui.input :label="__('Registered from')" name="from" type="date" wire:model.live="from" /></div>
                <div class="col-6 col-lg-3"><x-ui.input :label="__('Registered to')" name="to" type="date" wire:model.live="to" /></div>
                <div class="col-6 col-lg-3">
                    <x-ui.select :label="__('Last active')" name="active" wire:model.live="active" :placeholder="__('Any time')"
                                 :options="['7d' => __('In the last 7 days'), '30d' => __('In the last 30 days'), '90d' => __('In the last 90 days'), '90d+' => __('Not for 90+ days')]" />
                </div>
                <div class="col-6 col-lg-3">
                    <x-ui.select :label="__('Sort')" name="sort" wire:model.live="sort"
                                 :options="['newest' => __('Newest first'), 'oldest' => __('Oldest first'), 'last_active' => __('Recently active')]" />
                </div>
            </div>
        </details>
        <div class="mt-2">
            <x-ui.button size="sm" variant="ghost" type="button" wire:click="clearFilters" icon="fa-times">{{ __('Clear filters') }}</x-ui.button>
        </div>
    </div>

    @canany(['members.suspend', 'members.edit'])
        <div class="admin-bulk-bar mb-3" aria-live="polite">
            <span>{{ trans_choice(':count selected|:count selected', count($selected)) }}</span>
            <x-ui.button size="sm" variant="ghost" type="button" wire:click="selectPage">{{ __('Select this page') }}</x-ui.button>
            @if ($selected !== [])
                <x-ui.button size="sm" variant="ghost" type="button" wire:click="$set('selected', [])">{{ __('Clear selection') }}</x-ui.button>
                <x-ui.button size="sm" type="button" icon="fa-bolt" x-on:click="$dispatch('open-modal', { name: 'bulk-action' })">{{ __('Bulk action…') }}</x-ui.button>
            @endif
        </div>
    @endcanany

    <div wire:loading.class="opacity-50" wire:target="q,status,profileStatus,verified,plan,completeness,gender,religion,district,from,to,active,sort,gotoPage,nextPage,previousPage">
        <x-admin.table :headers="['', __('Code'), __('Name'), __('Gender / age'), __('Phone'), __('Account'), __('Profile'), __('Registered'), __('Last active')]"
                       :caption="__('Members')" :empty="$this->members->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-users" :title="__('No members match')" :message="__('Try a different search or clear the filters.')" />
            </x-slot:emptyState>

            @foreach ($this->members as $member)
                @php($profile = $member->profile)
                <tr wire:key="member-{{ $member->id }}">
                    <td>
                        <label class="visually-hidden" for="sel-{{ $member->id }}">{{ __('Select :code', ['code' => $profile?->code]) }}</label>
                        <input type="checkbox" class="form-check-input" id="sel-{{ $member->id }}" name="selected[]" value="{{ $member->id }}" wire:model.live="selected">
                    </td>
                    <td>
                        @if ($profile)
                            <a href="{{ route('admin.members.show', ['profile' => $profile->code]) }}" wire:navigate>{{ $profile->code }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $profile?->fullName() ?? '—' }}</td>
                    <td>{{ $profile?->gender->label() }}@if ($profile?->age()) · {{ $profile->age() }}@endif</td>
                    <td>•••• {{ substr($member->phone, -4) }}</td>
                    <td><x-ui.badge :variant="$member->status->badgeVariant()">{{ $member->status->label() }}</x-ui.badge></td>
                    <td>
                        @if ($profile)
                            <x-ui.badge :variant="$profile->status->badgeVariant()">{{ $profile->status->label() }}</x-ui.badge>
                            @if ($profile->is_premium)<x-ui.badge variant="premium" icon="fa-diamond">{{ __('Paid') }}</x-ui.badge>@endif
                            @if ($profile->is_verified)<x-ui.badge variant="verified" icon="fa-check">{{ __('Verified') }}</x-ui.badge>@endif
                        @endif
                    </td>
                    <td>{{ $member->created_at?->timezone($timezone)->format('d M Y') }}</td>
                    <td>{{ $profile?->last_active_at?->timezone($timezone)->format('d M Y') ?? __('Never') }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    </div>

    <div class="mt-3">{{ $this->members->links() }}</div>

    <x-ui.modal name="bulk-action" :title="__('Bulk action')">
        <p>{{ trans_choice('Applies to :count selected member.|Applies to :count selected members.', count($selected)) }} {{ __('Members in the wrong state are skipped. There is no bulk delete.') }}</p>
        <x-ui.select :label="__('Action')" name="bulkAction" id="bulk-action-select" wire:model.live="bulkAction" :options="$bulkActions" :placeholder="__('Choose…')" />
        @if ($bulkAction === 'NOTIFY')
            <x-ui.input :label="__('Email subject')" name="bulkSubject" wire:model="bulkSubject" />
            <x-ui.textarea :label="__('Message')" name="bulkMessage" wire:model="bulkMessage" rows="4" />
        @endif
        <x-ui.textarea :label="__('Reason (recorded in the audit log)')" name="reason" id="bulk-reason" wire:model="reason" rows="2" required />
        @error('selected')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror
        <x-slot:footer>
            <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="button" wire:click="runBulk" loading="runBulk">{{ __('Apply') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
