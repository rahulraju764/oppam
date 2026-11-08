<div>
    <div class="admin-page-head d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Business Rules') }}</h1>
            <p class="text-muted mb-0">{{ __('System operational thresholds, cooldowns, and limits (PRD §11 A15). All edits are audited.') }}</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- Category Tabs & Search Filter -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-3">
            <div class="row g-2 align-items-center justify-content-between">
                <div class="col-md-8">
                    <div class="d-flex flex-wrap gap-1">
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'all')">
                            {{ __('All Rules') }}
                        </button>
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'profile' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'profile')">
                            <i class="fa fa-user me-1" aria-hidden="true"></i> {{ __('Profile') }}
                        </button>
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'matching' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'matching')">
                            <i class="fa fa-heart me-1" aria-hidden="true"></i> {{ __('Matching') }}
                        </button>
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'chat' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'chat')">
                            <i class="fa fa-comments me-1" aria-hidden="true"></i> {{ __('Chat') }}
                        </button>
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'media' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'media')">
                            <i class="fa fa-camera me-1" aria-hidden="true"></i> {{ __('Media') }}
                        </button>
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'notifications' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'notifications')">
                            <i class="fa fa-bell me-1" aria-hidden="true"></i> {{ __('Comms') }}
                        </button>
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'broker' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'broker')">
                            <i class="fa fa-briefcase me-1" aria-hidden="true"></i> {{ __('Brokers') }}
                        </button>
                        <button type="button" class="btn btn-sm {{ $activeCategory === 'auth' ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="$set('activeCategory', 'auth')">
                            <i class="fa fa-shield me-1" aria-hidden="true"></i> {{ __('Auth / OTP') }}
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fa fa-search"></i></span>
                        <input type="text" class="form-control" placeholder="{{ __('Search rule key or name...') }}" wire:model.live.debounce.300ms="search">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rules Sections -->
    <div class="space-y-4">
        @forelse ($categories as $category)
            <div class="card shadow-sm border-0 mb-4" wire:key="category-{{ $category['id'] }}">
                <div class="card-header bg-transparent py-3 d-flex align-items-center gap-2">
                    <i class="fa {{ $category['icon'] }} text-primary"></i>
                    <h5 class="card-title mb-0">{{ __($category['name']) }}</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 35%;">{{ __('Rule') }}</th>
                                <th style="width: 35%;">{{ __('Description') }}</th>
                                <th style="width: 15%;">{{ __('Current Value') }}</th>
                                <th style="width: 15%;" class="text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($category['items'] as $item)
                                <tr wire:key="rule-{{ $item['key'] }}">
                                    <td>
                                        <div class="fw-bold">{{ $item['label'] }}</div>
                                        <code class="text-muted small">{{ $item['key'] }}</code>
                                    </td>
                                    <td class="text-muted small">
                                        {{ $item['description'] }}
                                        <div class="text-secondary small mt-1">{{ __('Default:') }} {{ $item['default'] }} {{ $item['unit'] }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border fs-6 px-3 py-1 font-monospace">
                                            {{ $item['current'] }} <span class="text-muted small">{{ $item['unit'] }}</span>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @can('settings.edit')
                                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="openEdit('{{ $item['key'] }}')" dusk="edit-rule-{{ str_replace('.', '-', $item['key']) }}">
                                                <i class="fa fa-pencil me-1"></i> {{ __('Edit') }}
                                            </button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="card shadow-sm border-0 py-5 text-center text-muted">
                <i class="fa fa-sliders fa-3x mb-3 text-secondary"></i>
                <h5>{{ __('No business rules matched your criteria.') }}</h5>
            </div>
        @endforelse
    </div>

    <!-- Edit Rule Modal -->
    @if ($showEditModal)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Edit Rule: :name', ['name' => $editingLabel]) }}</h5>
                        <button type="button" class="btn-close" wire:click="closeEdit" aria-label="Close"></button>
                    </div>
                    <form wire:submit="save">
                        <div class="modal-body">
                            <p class="text-muted small mb-3">{{ $editingDescription }}</p>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">{{ __('Rule Value') }}</label>
                                <input type="text" class="form-control font-monospace @error('value') is-invalid @enderror" wire:model="editingValue" autofocus>
                                <div class="form-text small text-muted">
                                    {{ __('Constraints:') }} <code>{{ $editingRulesDescription }}</code> | {{ __('Default:') }} <strong>{{ $editingDefault }}</strong>
                                </div>
                                @error('value') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">{{ __('Audit Reason (Required)') }}</label>
                                <textarea class="form-control @error('reason') is-invalid @enderror" rows="2" wire:model="reason" placeholder="{{ __('Why is this threshold being changed?') }}" required></textarea>
                                @error('reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" wire:click="closeEdit">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary" dusk="save-rule-modal-btn">
                                <i class="fa fa-save me-1"></i> {{ __('Save Change') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

