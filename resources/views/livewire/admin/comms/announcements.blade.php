<div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Announcements & Banners') }}</h1>
            <p class="text-muted mb-0">{{ __('Broadcast real-time offers and scheduled alerts to active members (PRD §11 A14).') }}</p>
        </div>
        <button type="button" class="btn btn-primary" wire:click="openCreateModal" dusk="create-announcement-btn">
            <i class="fa fa-bullhorn me-1" aria-hidden="true"></i> {{ __('New Announcement') }}
        </button>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Title') }}</th>
                        <th>{{ __('Schedule / Window') }}</th>
                        <th>{{ __('Target') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Last Broadcast') }}</th>
                        <th>{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($announcements as $a)
                        <tr>
                            <td>
                                <strong>{{ $a->title }}</strong>
                                <div class="text-muted small text-truncate" style="max-width: 300px;">{{ $a->body }}</div>
                            </td>
                            <td>
                                <small>
                                    {{ $a->starts_at ? $a->starts_at->format('d M Y') : __('Immediate') }}
                                    – {{ $a->ends_at ? $a->ends_at->format('d M Y') : __('Ongoing') }}
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">{{ $a->target_plan ?? __('All Members') }}</span>
                            </td>
                            <td>
                                @if ($a->is_active)
                                    <span class="badge bg-success">{{ __('Active') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <small class="text-muted">{{ $a->broadcast_at ? $a->broadcast_at->format('d M, h:i A') : __('Never') }}</small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" wire:click="broadcast('{{ $a->id }}')" title="{{ __('Broadcast live to online users') }}">
                                        <i class="fa fa-wifi me-1" aria-hidden="true"></i> {{ __('Broadcast') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" wire:click="toggleActive('{{ $a->id }}')">
                                        {{ $a->is_active ? __('Deactivate') : __('Activate') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                {{ __('No announcements found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($announcements->hasPages())
            <div class="card-footer">
                {{ $announcements->links() }}
            </div>
        @endif
    </div>

    {{-- CREATE MODAL --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Create Announcement') }}</h5>
                        <button type="button" class="btn-close" wire:click="$set('showModal', false)"></button>
                    </div>
                    <form wire:submit="save">
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">{{ __('Title / Heading') }}</label>
                                    <input type="text" class="form-control" wire:model="title" placeholder="e.g. Onam Special Offer: 20% off Gold" required>
                                    @error('title')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label">{{ __('Message Body') }}</label>
                                    <textarea class="form-control" rows="3" wire:model="body" placeholder="Announcement text..." required></textarea>
                                    @error('body')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Action URL (optional)') }}</label>
                                    <input type="url" class="form-control" wire:model="actionUrl" placeholder="https://...">
                                    @error('actionUrl')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Action Button Label') }}</label>
                                    <input type="text" class="form-control" wire:model="actionText" placeholder="e.g. Upgrade Now">
                                    @error('actionText')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Starts At (optional)') }}</label>
                                    <input type="datetime-local" class="form-control" wire:model="startsAt">
                                    @error('startsAt')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Ends At (optional)') }}</label>
                                    <input type="datetime-local" class="form-control" wire:model="endsAt">
                                    @error('endsAt')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Target Plan') }}</label>
                                    <select class="form-select" wire:model="targetPlan">
                                        <option value="">{{ __('All Plans') }}</option>
                                        <option value="FREE">{{ __('Free Plan Only') }}</option>
                                        <option value="SILVER">{{ __('Silver Only') }}</option>
                                        <option value="GOLD">{{ __('Gold Only') }}</option>
                                        <option value="DIAMOND">{{ __('Diamond Only') }}</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="dismissCheck" wire:model="isDismissible">
                                        <label class="form-check-label" for="dismissCheck">{{ __('Allow members to dismiss this banner') }}</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="broadcastCheck" wire:model="broadcastNow">
                                        <label class="form-check-label" for="broadcastCheck">{{ __('Broadcast live via WebSocket immediately upon save') }}</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="$set('showModal', false)">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Save Announcement') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>

