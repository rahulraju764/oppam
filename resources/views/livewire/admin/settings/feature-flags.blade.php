<div>
    <div class="admin-page-head d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Feature Flags') }}</h1>
            <p class="text-muted mb-0">{{ __('Control platform features and experimental capabilities (PRD §11 A15). Toggling flags is audited.') }}</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 35%;">{{ __('Feature Flag') }}</th>
                        <th style="width: 45%;">{{ __('Description') }}</th>
                        <th style="width: 10%;">{{ __('Status') }}</th>
                        <th style="width: 10%;" class="text-end">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($flags as $flag)
                        <tr wire:key="flag-{{ $flag['key'] }}">
                            <td>
                                <div class="fw-bold">{{ $flag['name'] }}</div>
                                <code class="text-muted small">{{ $flag['key'] }}</code>
                            </td>
                            <td class="text-muted small">
                                {{ $flag['description'] }}
                            </td>
                            <td>
                                @if ($flag['isActive'])
                                    <span class="badge bg-success">{{ __('Enabled') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('Disabled') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('settings.edit')
                                    @if ($flag['isActive'])
                                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="promptToggle('{{ $flag['key'] }}', false)" dusk="disable-flag-{{ str_replace('.', '-', $flag['key']) }}">
                                            <i class="fa fa-toggle-on me-1"></i> {{ __('Disable') }}
                                        </button>
                                    @else
                                        <button type="button" class="btn btn-sm btn-outline-success" wire:click="promptToggle('{{ $flag['key'] }}', true)" dusk="enable-flag-{{ str_replace('.', '-', $flag['key']) }}">
                                            <i class="fa fa-toggle-off me-1"></i> {{ __('Enable') }}
                                        </button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Toggle Confirmation Modal -->
    @if ($showConfirmModal)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            @if ($targetState)
                                <i class="fa fa-check-circle text-success me-1"></i> {{ __('Enable Feature: :name', ['name' => $selectedFlagLabel]) }}
                            @else
                                <i class="fa fa-exclamation-triangle text-warning me-1"></i> {{ __('Disable Feature: :name', ['name' => $selectedFlagLabel]) }}
                            @endif
                        </h5>
                        <button type="button" class="btn-close" wire:click="cancelToggle" aria-label="Close"></button>
                    </div>
                    <form wire:submit="confirmToggle">
                        <div class="modal-body">
                            <p class="mb-3">
                                {{ __('Are you sure you want to change the state of :key to :state?', ['key' => $selectedFlag, 'state' => $targetState ? __('ENABLED') : __('DISABLED')]) }}
                            </p>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">{{ __('Audit Reason (Required)') }}</label>
                                <textarea class="form-control @error('reason') is-invalid @enderror" rows="2" wire:model="reason" placeholder="{{ __('State why you are toggling this feature flag...') }}" required autofocus></textarea>
                                @error('reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" wire:click="cancelToggle">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn {{ $targetState ? 'btn-success' : 'btn-danger' }}" dusk="confirm-toggle-btn">
                                <i class="fa {{ $targetState ? 'fa-check' : 'fa-power-off' }} me-1"></i> {{ $targetState ? __('Enable Feature') : __('Disable Feature') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

