{{--
    A07 Applications & KYC queue. The PAN image opens only on demand, in the in-browser viewer
    (watermarked image, or pdf.js canvases) — no download control, logged on every fetch.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Bureau applications & KYC') }}</h1>
            <p>{{ __('Approve a KYC submission to activate the bureau. Rejected KYC can be sent again; a rejected application closes the bureau.') }}</p>
        </div>
    </div>

    <nav class="admin-tabs mb-3" aria-label="{{ __('KYC lists') }}">
        @foreach (\App\Livewire\Admin\Brokers\KycQueue::LISTS as $key)
            <button type="button" class="admin-tab @if ($list === $key) active @endif" wire:click="show('{{ $key }}')" @if ($list === $key) aria-current="page" @endif>
                {{ match ($key) { 'kyc' => __('KYC to review'), 'applications' => __('Applications (no KYC yet)'), default => __('Decided (90 days)') } }}
                @isset($counts[$key])<span class="nav-count">{{ $counts[$key] }}</span>@endisset
            </button>
        @endforeach
    </nav>

    <div class="row g-4">
        <div @class(['col-12', 'col-xl-7' => $broker !== null])>
            <x-admin.table :headers="[__('Bureau'), __('Owner'), __('District'), __('Status'), __('Since (IST)'), '']" :caption="__('Bureaus')" :empty="$this->brokers->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-id-card-o" :title="__('All caught up')" :message="__('Nothing is waiting in this list.')" />
                </x-slot:emptyState>

                @foreach ($this->brokers as $row)
                    <tr wire:key="br-{{ $row->code }}" @class(['is-selected' => $row->code === $selected])>
                        <td><strong>{{ $row->code }}</strong><div class="small">{{ $row->displayName() }}</div></td>
                        <td>{{ $row->name }}</td>
                        <td>{{ $row->district?->label }}</td>
                        <td>
                            <x-ui.badge :variant="$row->status === \App\Enums\BrokerStatus::Active ? 'verified' : 'default'">{{ $row->status->label() }}</x-ui.badge>
                            <div class="small text-muted-brand">{{ __('KYC: :s', ['s' => $row->kyc_status->label()]) }}</div>
                        </td>
                        <td>{{ ($row->latestKyc?->created_at ?? $row->created_at)->timezone($timezone)->format('d M, g:i a') }}</td>
                        <td class="text-end">
                            <x-ui.button size="sm" type="button" wire:click="select('{{ $row->code }}')" loading="select">{{ __('Open') }}</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            {{ $this->brokers->links() }}
        </div>

        @if ($broker)
            <div class="col-xl-5 col-12">
                <div class="ui-card mb-4">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="h5 mb-0">{{ $broker->displayName() }} <span class="text-muted-brand">{{ $broker->code }}</span></h2>
                        <button type="button" class="btn-close" wire:click="select('')" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <dl class="mod-facts mt-3">
                        <div><dt>{{ __('Owner') }}</dt><dd>{{ $broker->name }}</dd></div>
                        <div><dt>{{ __('Mobile') }}</dt><dd>{{ $broker->phone }}</dd></div>
                        <div><dt>{{ __('Email') }}</dt><dd>{{ $broker->email ?? '—' }}</dd></div>
                        <div><dt>{{ __('District') }}</dt><dd>{{ $broker->district?->label ?? '—' }}</dd></div>
                        <div><dt>{{ __('PAN (application)') }}</dt><dd>{{ $broker->pan_last4 ? 'XXXXXX'.$broker->pan_last4 : '—' }}</dd></div>
                        <div><dt>{{ __('Applied') }}</dt><dd>{{ $broker->created_at->timezone($timezone)->format('d M Y, g:i a') }}</dd></div>
                        @if ($kyc)
                            <div><dt>{{ __('KYC PAN') }}</dt><dd>{{ $kyc->maskedPan() }}</dd></div>
                            <div><dt>{{ __('Bank account') }}</dt><dd>{{ $kyc->maskedAccount() }} · {{ $kyc->ifsc }}</dd></div>
                            <div><dt>{{ __('Account holder') }}</dt><dd>{{ $kyc->account_name }}</dd></div>
                            <div><dt>{{ __('KYC sent') }}</dt><dd>{{ $kyc->created_at->timezone($timezone)->format('d M Y, g:i a') }} · {{ $kyc->status->label() }}</dd></div>
                        @endif
                    </dl>

                    @if ($kyc && $kyc->pan_image_path !== null)
                        @if ($panUrl === '')
                            <p class="small text-muted-brand">{{ __('Opening the PAN image is logged, and so is every load. The link works for :m minutes in this browser only.', ['m' => config('oppam.broker.kyc_link_minutes')]) }}</p>
                            <x-ui.button type="button" variant="outline" size="sm" icon="fa-eye" wire:click="viewPan" loading="viewPan" dusk="kyc-view-pan">{{ __('View PAN image') }}</x-ui.button>
                        @else
                            <p class="small"><i class="fa fa-lock" aria-hidden="true"></i> {{ __('Access is logged. Don’t save, copy or photograph this document.') }}</p>
                            <figure class="vf-viewer" wire:key="pan-{{ $panUntil }}" x-data x-on:contextmenu.prevent x-on:dragstart.prevent>
                                <figcaption>{{ __('PAN card') }}</figcaption>
                                @if ($kyc->pan_image_mime === 'application/pdf')
                                    <div class="vf-viewer__pdf" x-data="pdfDocument(@js($panUrl), @js($watermark))" role="img" aria-label="{{ __('PAN card (PDF)') }}">
                                        <p class="small text-muted-brand" x-show="loading">{{ __('Loading…') }}</p>
                                        <p class="small" x-show="failed" x-cloak>{{ __('The document could not be shown. The link may have expired — close and open it again.') }}</p>
                                        <div x-ref="pages"></div>
                                    </div>
                                @else
                                    <img src="{{ $panUrl }}" alt="{{ __('PAN card') }}" referrerpolicy="no-referrer" draggable="false">
                                @endif
                                <div class="vf-viewer__watermark" aria-hidden="true">
                                    @for ($i = 0; $i < 12; $i++)<span>{{ $watermark }}</span>@endfor
                                </div>
                            </figure>
                            <x-ui.button type="button" variant="ghost" size="sm" icon="fa-times" wire:click="closePan">{{ __('Close viewer') }}</x-ui.button>
                        @endif
                    @endif
                </div>

                <div class="ui-card mod-decision mb-4">
                    <h2 class="h5">{{ __('Decision') }}</h2>
                    @if ($kyc?->status === \App\Enums\KycSubmissionStatus::Pending || in_array($broker->status, [\App\Enums\BrokerStatus::Applied, \App\Enums\BrokerStatus::KycPending], true))
                        @if ($kyc?->status === \App\Enums\KycSubmissionStatus::Pending)
                            <x-ui.button class="w-100 mb-3" type="button" wire:click="approve" loading="approve" icon="fa-check" dusk="kyc-approve">{{ __('Approve KYC — activate bureau') }}</x-ui.button>
                        @endif
                        <x-ui.textarea :label="__('Reason (shown to the owner)')" name="reason" wire:model="reason" rows="2" />
                        @if ($kyc?->status === \App\Enums\KycSubmissionStatus::Pending)
                            <x-ui.button class="w-100 mb-2" variant="outline" type="button" wire:click="rejectKyc" loading="rejectKyc" icon="fa-undo">{{ __('Reject KYC (owner resends)') }}</x-ui.button>
                        @endif
                        @if (in_array($broker->status, [\App\Enums\BrokerStatus::Applied, \App\Enums\BrokerStatus::KycPending], true))
                            <x-ui.button class="w-100" variant="danger" type="button" wire:click="rejectApplication" loading="rejectApplication" icon="fa-times"
                                         wire:confirm="{{ __('Reject this application and close the bureau?') }}">{{ __('Reject application') }}</x-ui.button>
                        @endif
                    @else
                        <p class="text-muted-brand mb-0">{{ __('Nothing to decide: the bureau is :status.', ['status' => strtolower($broker->status->label())]) }}</p>
                    @endif
                </div>

                @if ($history->isNotEmpty())
                    <div class="ui-card">
                        <h2 class="h6">{{ __('KYC history') }}</h2>
                        <ul class="list-unstyled small mb-0">
                            @foreach ($history as $item)
                                <li wire:key="kyc-{{ $item->id }}">{{ $item->created_at->timezone($timezone)->format('d M Y') }} · {{ $item->status->label() }}
                                    @if ($item->reviewedBy) — {{ $item->reviewedBy->name }}@endif
                                    @if ($item->rejection_reason)<div class="text-muted-brand">“{{ $item->rejection_reason }}”</div>@endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
