{{--
    A07 Unverified referral codes (§11A B.12): members whose code matched no active bureau, with
    fuzzy suggestions. Link to a bureau or clear — both need a reason and are audited; never automatic.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Unverified referral codes') }}</h1>
            <p>{{ __('Members who typed a code that matched no active bureau. Link the right bureau, or clear the code. Nothing is linked automatically.') }}</p>
        </div>
    </div>

    <div class="row g-4">
        <div @class(['col-12', 'col-xl-7' => $profile !== null])>
            <x-admin.table :headers="[__('Profile'), __('Code typed'), __('Suggestions'), __('Joined (IST)'), '']" :caption="__('Unverified codes (:n)', ['n' => $total])" :empty="$rows->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-check-circle-o" :title="__('All caught up')" :message="__('No codes are waiting for review.')" />
                </x-slot:emptyState>

                @foreach ($rows as $row)
                    <tr wire:key="uc-{{ $row->code }}" @class(['is-selected' => $row->code === $selected])>
                        <td><strong>{{ $row->code }}</strong><div class="small text-muted-brand">{{ $row->status->label() }}</div></td>
                        <td><code>{{ $row->broker_code_raw }}</code></td>
                        <td>
                            @forelse ($suggestionsByRow[$row->code] ?? [] as $broker)
                                <span class="chip">{{ $broker->code }}</span>
                            @empty
                                <span class="text-muted-brand small">{{ __('None close') }}</span>
                            @endforelse
                        </td>
                        <td>{{ $row->created_at->timezone($timezone)->format('d M, g:i a') }}</td>
                        <td class="text-end">
                            <x-ui.button size="sm" type="button" wire:click="select('{{ $row->code }}')" loading="select" dusk="uc-open-{{ $row->code }}">{{ __('Review') }}</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            {{ $rows->links() }}
        </div>

        @if ($profile)
            <div class="col-xl-5 col-12">
                <div class="ui-card mod-decision">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="h5 mb-0">{{ $profile->code }} · <code>{{ $profile->broker_code_raw }}</code></h2>
                        <button type="button" class="btn-close" wire:click="close" aria-label="{{ __('Close') }}"></button>
                    </div>

                    <fieldset class="mt-3">
                        <legend class="h6">{{ __('Link to a bureau') }}</legend>
                        @forelse ($suggestions as $broker)
                            <div class="form-check" wire:key="sg-{{ $broker->code }}">
                                <input class="form-check-input" type="radio" name="brokerCode" id="sg-{{ $broker->code }}" value="{{ $broker->code }}" wire:model="brokerCode">
                                <label class="form-check-label" for="sg-{{ $broker->code }}">
                                    <strong>{{ $broker->code }}</strong> · {{ $broker->displayName() }}@if ($broker->district) , {{ $broker->district->label }}@endif
                                </label>
                            </div>
                        @empty
                            <p class="small text-muted-brand">{{ __('No active bureau code is within 2 characters of this one.') }}</p>
                        @endforelse
                        <x-ui.input :label="__('Or type a bureau code')" name="brokerCode" id="uc-broker-code" wire:model="brokerCode" maxlength="20" placeholder="BRK1042" />
                    </fieldset>

                    <x-ui.textarea :label="__('Reason (kept in the audit log)')" name="reason" wire:model="reason" rows="2" />

                    <x-ui.button class="w-100 mb-2" type="button" wire:click="link" loading="link" icon="fa-link" dusk="uc-link"
                                 wire:confirm="{{ __('Link this member to the chosen bureau? Attribution is permanent.') }}">{{ __('Link to bureau') }}</x-ui.button>
                    <x-ui.button class="w-100" variant="outline" type="button" wire:click="clear" loading="clear" icon="fa-eraser">{{ __('Clear code') }}</x-ui.button>
                </div>
            </div>
        @endif
    </div>
</div>
