<div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Message Templates') }}</h1>
            <p class="text-muted mb-0">{{ __('Manage Email, SMS DLT, and In-App notification copy (PRD §11 A14).') }}</p>
        </div>
        <button type="button" class="btn btn-primary" wire:click="openCreateModal" dusk="create-template-btn">
            <i class="fa fa-plus me-1" aria-hidden="true"></i> {{ __('New Template') }}
        </button>
    </div>

    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm {{ $channelFilter === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('channelFilter', 'all')">
                    {{ __('All Channels') }}
                </button>
                <button type="button" class="btn btn-sm {{ $channelFilter === 'email' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('channelFilter', 'email')">
                    {{ __('Email') }}
                </button>
                <button type="button" class="btn btn-sm {{ $channelFilter === 'sms' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('channelFilter', 'sms')">
                    {{ __('SMS (DLT)') }}
                </button>
                <button type="button" class="btn btn-sm {{ $channelFilter === 'in_app' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('channelFilter', 'in_app')">
                    {{ __('In-App') }}
                </button>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Key') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Channel') }}</th>
                        <th>{{ __('Subject / DLT ID') }}</th>
                        <th>{{ __('Version') }}</th>
                        <th>{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $t)
                        <tr>
                            <td><code>{{ $t->key }}</code></td>
                            <td><strong>{{ $t->name }}</strong></td>
                            <td>
                                <span class="badge bg-secondary text-uppercase">{{ $t->channel }}</span>
                            </td>
                            <td>
                                @if ($t->channel === 'email')
                                    <small>{{ $t->subject }}</small>
                                @elseif ($t->channel === 'sms')
                                    <code>{{ $t->dlt_template_id ?? '—' }}</code>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td><span class="badge bg-light text-dark">v{{ $t->version }}</span></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary" wire:click="edit('{{ $t->id }}')">
                                        {{ __('Edit') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" wire:click="testSend('{{ $t->id }}')" title="{{ __('Test send to self') }}">
                                        {{ __('Test Send') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                {{ __('No templates found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($templates->hasPages())
            <div class="card-footer">
                {{ $templates->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingId ? __('Edit Template') : __('New Template') }}</h5>
                        <button type="button" class="btn-close" wire:click="$set('showModal', false)"></button>
                    </div>
                    <form wire:submit="save">
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Template Key') }}</label>
                                    <input type="text" class="form-control" wire:model="key" placeholder="e.g. welcome_email" {{ $editingId ? 'readonly' : '' }} required>
                                    @error('key')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Name') }}</label>
                                    <input type="text" class="form-control" wire:model="name" placeholder="Friendly title" required>
                                    @error('name')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Channel') }}</label>
                                    <select class="form-select" wire:model.live="channel" required>
                                        <option value="email">{{ __('Email') }}</option>
                                        <option value="sms">{{ __('SMS') }}</option>
                                        <option value="in_app">{{ __('In-App Notification') }}</option>
                                    </select>
                                </div>
                                @if ($channel === 'email')
                                    <div class="col-md-6">
                                        <label class="form-label">{{ __('Email Subject') }}</label>
                                        <input type="text" class="form-control" wire:model="subject" placeholder="Email subject line">
                                        @error('subject')<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                @endif
                                @if ($channel === 'sms')
                                    <div class="col-md-6">
                                        <label class="form-label">{{ __('DLT Template ID') }}</label>
                                        <input type="text" class="form-control" wire:model="dltTemplateId" placeholder="Registered DLT template ID">
                                        @error('dltTemplateId')<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                @endif
                                <div class="col-12">
                                    <label class="form-label">{{ __('Body Content (Markdown or Text)') }}</label>
                                    <textarea class="form-control" rows="6" wire:model="body" placeholder="Use {name}, {code} for variables..." required></textarea>
                                    @error('body')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label">{{ __('Variables (JSON sample, optional)') }}</label>
                                    <textarea class="form-control font-monospace" rows="2" wire:model="variables" placeholder='{"name": "Ananya", "code": "OPM1001"}'></textarea>
                                    @error('variables')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Save Template') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

