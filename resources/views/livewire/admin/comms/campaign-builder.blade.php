<div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Campaign Builder') }}</h1>
            <p class="text-muted mb-0">{{ __('Design targeting criteria and compose message content (PRD §11 A14).') }}</p>
        </div>
        <a href="{{ route('admin.comms.campaigns.index') }}" class="btn btn-outline-secondary">
            {{ __('Back to Campaigns') }}
        </a>
    </div>

    <form wire:submit="save">
        <div class="row g-4">
            {{-- LEFT: COMPOSER --}}
            <div class="col-lg-7">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h2 class="h6 mb-0">{{ __('1. Message Details') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">{{ __('Campaign Name') }}</label>
                            <input type="text" class="form-control" wire:model="name" placeholder="e.g. Diwalii Match Boost 2026" required>
                            @error('name')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Channel') }}</label>
                                <select class="form-select" wire:model.live="channel" required>
                                    <option value="email">{{ __('Email') }}</option>
                                    <option value="sms">{{ __('SMS') }}</option>
                                    <option value="in_app">{{ __('In-App Notification') }}</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Load from Template (optional)') }}</label>
                                <select class="form-select" wire:model.live="templateId">
                                    <option value="">{{ __('Custom Message…') }}</option>
                                    @foreach ($templates as $t)
                                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if ($channel === 'email')
                            <div class="mb-3">
                                <label class="form-label">{{ __('Subject Line') }}</label>
                                <input type="text" class="form-control" wire:model="subject" placeholder="Email subject...">
                                @error('subject')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label">{{ __('Message Content') }}</label>
                            <textarea class="form-control" rows="5" wire:model="message" placeholder="Type message text..." required></textarea>
                            @error('message')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Schedule Time (optional, blank = immediate)') }}</label>
                                <input type="datetime-local" class="form-control" wire:model="scheduledAt">
                                @error('scheduledAt')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('Throttle Rate (recipients per min)') }}</label>
                                <input type="number" class="form-control" wire:model="throttlePerMinute" min="1" max="500">
                                @error('throttlePerMinute')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- RIGHT: SEGMENT FILTERS & AUDIENCE COUNTER --}}
            <div class="col-lg-5">
                <div class="card shadow-sm mb-4 border-primary">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">{{ __('Estimated Audience') }}</h2>
                        <span class="badge bg-light text-primary fs-6" dusk="audience-badge">{{ number_format($audienceCount) }}</span>
                    </div>
                    <div class="card-body">
                        @if ($audienceCount > 10000)
                            <div class="alert alert-warning py-2 mb-0 small">
                                <i class="fa fa-info-circle me-1" aria-hidden="true"></i>
                                {{ __('Audience exceeds 10,000 members. This campaign will require approval by a second admin before sending.') }}
                            </div>
                        @else
                            <p class="text-muted small mb-0">
                                {{ __('Members who match all active segment filters and have not opted out of communications.') }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h2 class="h6 mb-0">{{ __('2. Target Segment Filters') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label small">{{ __('Gender') }}</label>
                                <select class="form-select form-select-sm" wire:model.live="gender">
                                    <option value="">{{ __('Any') }}</option>
                                    <option value="MALE">{{ __('Groom (Male)') }}</option>
                                    <option value="FEMALE">{{ __('Bride (Female)') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">{{ __('Membership') }}</label>
                                <select class="form-select form-select-sm" wire:model.live="isPremium">
                                    <option value="">{{ __('All Plans') }}</option>
                                    <option value="1">{{ __('Paid Members Only') }}</option>
                                    <option value="0">{{ __('Free Members Only') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">{{ __('Religion') }}</label>
                                <select class="form-select form-select-sm" wire:model.live="religionId">
                                    <option value="">{{ __('Any Religion') }}</option>
                                    @foreach ($religions as $r)
                                        <option value="{{ $r->id }}">{{ $r->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">{{ __('District') }}</label>
                                <select class="form-select form-select-sm" wire:model.live="districtId">
                                    <option value="">{{ __('Any District') }}</option>
                                    @foreach ($districts as $d)
                                        <option value="{{ $d->id }}">{{ $d->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">{{ __('ID Verified') }}</label>
                                <select class="form-select form-select-sm" wire:model.live="isVerified">
                                    <option value="">{{ __('Either') }}</option>
                                    <option value="1">{{ __('Verified Only') }}</option>
                                    <option value="0">{{ __('Unverified Only') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">{{ __('Has Photo') }}</label>
                                <select class="form-select form-select-sm" wire:model.live="hasPhoto">
                                    <option value="">{{ __('Either') }}</option>
                                    <option value="1">{{ __('With Photo Only') }}</option>
                                    <option value="0">{{ __('Without Photo') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">{{ __('Bureau Managed') }}</label>
                                <select class="form-select form-select-sm" wire:model.live="isBroker">
                                    <option value="">{{ __('Either') }}</option>
                                    <option value="1">{{ __('Broker Managed') }}</option>
                                    <option value="0">{{ __('Direct Signups') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">{{ __('Min Completeness %') }}</label>
                                <input type="number" class="form-control form-control-sm" wire:model.live="completenessMin" min="0" max="100" placeholder="e.g. 50">
                            </div>
                            <div class="col-12">
                                <label class="form-label small">{{ __('Active in Last N Days') }}</label>
                                <input type="number" class="form-control form-control-sm" wire:model.live="activeWithinDays" min="1" placeholder="e.g. 14">
                            </div>
                        </div>

                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary" dusk="save-campaign-btn">
                                <i class="fa fa-paper-plane me-1" aria-hidden="true"></i> {{ __('Save & Schedule Campaign') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
