<div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Campaigns') }}</h1>
            <p class="text-muted mb-0">{{ __('Outbound communications across Email, SMS, and In-App with segmentation (PRD §11 A14).') }}</p>
        </div>
        <a href="{{ route('admin.comms.campaigns.builder') }}" class="btn btn-primary" dusk="new-campaign-btn">
            <i class="fa fa-plus me-1" aria-hidden="true"></i> {{ __('New Campaign') }}
        </a>
    </div>

    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm w-auto" wire:model.live="channelFilter">
                        <option value="all">{{ __('All Channels') }}</option>
                        <option value="email">{{ __('Email') }}</option>
                        <option value="sms">{{ __('SMS') }}</option>
                        <option value="in_app">{{ __('In-App') }}</option>
                    </select>
                    <select class="form-select form-select-sm w-auto" wire:model.live="statusFilter">
                        <option value="all">{{ __('All Statuses') }}</option>
                        <option value="DRAFT">{{ __('Draft') }}</option>
                        <option value="PENDING_APPROVAL">{{ __('Pending Approval') }}</option>
                        <option value="APPROVED">{{ __('Approved') }}</option>
                        <option value="SCHEDULED">{{ __('Scheduled') }}</option>
                        <option value="SENDING">{{ __('Sending') }}</option>
                        <option value="COMPLETED">{{ __('Completed') }}</option>
                        <option value="CANCELLED">{{ __('Cancelled') }}</option>
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
                        <th>{{ __('Campaign') }}</th>
                        <th>{{ __('Channel') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Audience') }}</th>
                        <th>{{ __('Progress') }}</th>
                        <th>{{ __('Creator / Approver') }}</th>
                        <th>{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $c)
                        <tr>
                            <td>
                                <strong>{{ $c->name }}</strong>
                                @if ($c->subject)
                                    <div class="small text-muted text-truncate" style="max-width: 250px;">{{ $c->subject }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-secondary text-uppercase">{{ $c->channel }}</span>
                            </td>
                            <td>
                                @php
                                    $badgeClass = match ($c->status) {
                                        'COMPLETED' => 'bg-success',
                                        'SENDING' => 'bg-primary',
                                        'APPROVED' => 'bg-info text-dark',
                                        'PENDING_APPROVAL' => 'bg-warning text-dark',
                                        'CANCELLED' => 'bg-dark',
                                        default => 'bg-secondary',
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }}">{{ $c->status }}</span>
                            </td>
                            <td>
                                <strong>{{ number_format($c->recipient_count) }}</strong>
                            </td>
                            <td>
                                <div class="small">
                                    <span>{{ __('Sent') }}: {{ number_format($c->sent_count) }}</span> /
                                    <span class="text-success">{{ __('Delivered') }}: {{ number_format($c->delivered_count) }}</span>
                                    @if ($c->failed_count > 0)
                                        / <span class="text-danger">{{ __('Failed') }}: {{ number_format($c->failed_count) }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <small>
                                    {{ $c->creator->name }}
                                    @if ($c->approver)
                                        <br><span class="text-muted">{{ __('Approved by :name', ['name' => $c->approver->name]) }}</span>
                                    @endif
                                </small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    @if ($c->status === 'PENDING_APPROVAL')
                                        <button type="button" class="btn btn-warning" wire:click="approve('{{ $c->id }}')">
                                            {{ __('Approve') }}
                                        </button>
                                    @endif
                                    @if ($c->status === 'APPROVED' || $c->status === 'SCHEDULED')
                                        <button type="button" class="btn btn-success" wire:click="send('{{ $c->id }}')">
                                            {{ __('Send Now') }}
                                        </button>
                                    @endif
                                    @if (in_array($c->status, ['PENDING_APPROVAL', 'APPROVED', 'SCHEDULED', 'SENDING'], true))
                                        <button type="button" class="btn btn-outline-danger" wire:click="cancel('{{ $c->id }}')">
                                            {{ __('Cancel') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                {{ __('No campaigns found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($campaigns->hasPages())
            <div class="card-footer">
                {{ $campaigns->links() }}
            </div>
        @endif
    </div>
</div>

