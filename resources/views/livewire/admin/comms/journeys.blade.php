<div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Automated Lifecycle Journeys') }}</h1>
            <p class="text-muted mb-0">{{ __('Configurable rule-based triggers across member lifecycle stages (PRD §11 A14).') }}</p>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Journey') }}</th>
                        <th>{{ __('Trigger Timing') }}</th>
                        <th>{{ __('Channel') }}</th>
                        <th>{{ __('Template') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($journeys as $j)
                        <tr>
                            <td>
                                <strong>{{ $j->name }}</strong>
                                <div class="text-muted small">{{ $j->description }}</div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    {{ trans_choice('{1} :count day after|[2,*] :count days after', $j->trigger_days, ['count' => $j->trigger_days]) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-secondary text-uppercase">{{ $j->channel }}</span>
                            </td>
                            <td>
                                @if ($j->template)
                                    <small>{{ $j->template->name }}</small>
                                @else
                                    <span class="text-muted small">{{ __('Default System Copy') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="journey-{{ $j->id }}"
                                           {{ $j->is_active ? 'checked' : '' }} wire:click="toggleActive('{{ $j->id }}')">
                                    <label class="form-check-label small" for="journey-{{ $j->id }}">
                                        {{ $j->is_active ? __('Active') : __('Paused') }}
                                    </label>
                                </div>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="edit('{{ $j->id }}')">
                                    {{ __('Configure') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- CONFIGURE MODAL --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Configure Journey: :name', ['name' => $editingName]) }}</h5>
                        <button type="button" class="btn-close" wire:click="$set('showModal', false)"></button>
                    </div>
                    <form wire:submit="save">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">{{ __('Trigger Delay (Days)') }}</label>
                                <input type="number" class="form-control" wire:model="triggerDays" min="1" max="365" required>
                                @error('triggerDays')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Channel') }}</label>
                                <select class="form-select" wire:model.live="channel" required>
                                    <option value="email">{{ __('Email') }}</option>
                                    <option value="sms">{{ __('SMS') }}</option>
                                    <option value="in_app">{{ __('In-App Notification') }}</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Custom Template (optional)') }}</label>
                                <select class="form-select" wire:model="templateId">
                                    <option value="">{{ __('Use Default System Template') }}</option>
                                    @foreach ($templates as $t)
                                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="journeyActiveSwitch" wire:model="isActive">
                                <label class="form-check-label" for="journeyActiveSwitch">{{ __('Enable automated journey') }}</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Save Journey Rule') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

