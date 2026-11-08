<div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Outbound Delivery Log') }}</h1>
            <p class="text-muted mb-0">{{ __('Audit log of outbound emails, SMS, and in-app communications with provider tracking (PRD §11 A14).') }}</p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="{{ __('Search recipient (phone, email)…') }}" wire:model.live.debounce.300ms="search">
                </div>
                <div class="col-md-3">
                    <select class="form-select form-select-sm" wire:model.live="channelFilter">
                        <option value="all">{{ __('All Channels') }}</option>
                        <option value="email">{{ __('Email') }}</option>
                        <option value="sms">{{ __('SMS') }}</option>
                        <option value="in_app">{{ __('In-App') }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select form-select-sm" wire:model.live="statusFilter">
                        <option value="all">{{ __('All Statuses') }}</option>
                        <option value="SENT">{{ __('Sent') }}</option>
                        <option value="DELIVERED">{{ __('Delivered') }}</option>
                        <option value="FAILED">{{ __('Failed') }}</option>
                        <option value="BOUNCED">{{ __('Bounced') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Recipient') }}</th>
                        <th>{{ __('Channel') }}</th>
                        <th>{{ __('Event / Subject') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Provider ID') }}</th>
                        <th>{{ __('Sent At') }}</th>
                        <th>{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($messages as $m)
                        <tr>
                            <td>
                                <code>{{ $m->recipient }}</code>
                            </td>
                            <td>
                                <span class="badge bg-secondary text-uppercase">{{ $m->channel }}</span>
                            </td>
                            <td>
                                <div>{{ $m->subject ?? $m->event ?? '—' }}</div>
                                @if ($m->error_message)
                                    <div class="text-danger small">{{ $m->error_message }}</div>
                                @endif
                            </td>
                            <td>
                                @php
                                    $badge = match ($m->status) {
                                        'DELIVERED' => 'bg-success',
                                        'SENT' => 'bg-primary',
                                        'FAILED' => 'bg-danger',
                                        'BOUNCED' => 'bg-warning text-dark',
                                        default => 'bg-secondary',
                                    };
                                @endphp
                                <span class="badge {{ $badge }}">{{ $m->status }}</span>
                            </td>
                            <td>
                                <small class="text-muted">{{ $m->provider_id ?? '—' }}</small>
                            </td>
                            <td>
                                <small>{{ $m->sent_at->format('d M Y, h:i A') }}</small>
                            </td>
                            <td>
                                @if (in_array($m->status, ['FAILED', 'BOUNCED'], true))
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="resend('{{ $m->id }}')">
                                        {{ __('Resend') }}
                                    </button>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                {{ __('No outbound message records found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($messages->hasPages())
            <div class="card-footer">
                {{ $messages->links() }}
            </div>
        @endif
    </div>
</div>

