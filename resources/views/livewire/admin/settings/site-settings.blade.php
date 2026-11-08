<div>
    <div class="admin-page-head d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Site Settings') }}</h1>
            <p class="text-muted mb-0">{{ __('Contact details, SEO indexing, and maintenance mode configuration (PRD §11 A15).') }}</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <form wire:submit="save">
        <div class="row g-4">
            <!-- Brand & Deployment Details -->
            <div class="col-lg-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h5 class="card-title mb-1">{{ __('Brand & Identity') }}</h5>
                                <p class="text-muted small mb-0">{{ __('Brand name, logo assets, and social links are defined in deployment config.') }}</p>
                            </div>
                            <span class="badge bg-light text-dark fs-6 px-3 py-2 border">{{ $brandName }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-transparent py-3">
                        <h5 class="card-title mb-0">{{ __('Contact Details') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">{{ __('Support Email') }}</label>
                            <input type="email" class="form-control @error('supportEmail') is-invalid @enderror" wire:model="supportEmail">
                            @error('supportEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">{{ __('Info / General Email') }}</label>
                            <input type="email" class="form-control @error('infoEmail') is-invalid @enderror" wire:model="infoEmail">
                            @error('infoEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">{{ __('Phone (Display Format)') }}</label>
                            <input type="text" class="form-control @error('phoneDisplay') is-invalid @enderror" wire:model="phoneDisplay" placeholder="0895 - 3881 - 2873">
                            @error('phoneDisplay') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">{{ __('Phone (Dial Format)') }}</label>
                            <input type="text" class="form-control @error('phoneDial') is-invalid @enderror" wire:model="phoneDial" placeholder="089538812873">
                            @error('phoneDial') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">{{ __('Office Address') }}</label>
                            <textarea class="form-control @error('address') is-invalid @enderror" rows="3" wire:model="address"></textarea>
                            @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold">{{ __('Working Hours') }}</label>
                            <input type="text" class="form-control @error('hours') is-invalid @enderror" wire:model="hours">
                            @error('hours') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEO, Maintenance & Content Controls -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-transparent py-3">
                        <h5 class="card-title mb-0">{{ __('SEO & Discovery') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="seoIndexable" wire:model="seoIndexable">
                            <label class="form-check-label fw-bold" for="seoIndexable">
                                {{ __('Search Engine Indexable (robots.txt & sitemap.xml)') }}
                            </label>
                            <div class="form-text text-muted">
                                {{ __('When disabled, search engines are instructed not to index any page (Disallow: /).') }}
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">{{ __('Min Home Featured Members') }}</label>
                                <input type="number" min="1" max="6" class="form-control @error('homeMembersMin') is-invalid @enderror" wire:model="homeMembersMin">
                                @error('homeMembersMin') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">{{ __('Home Toggle Max / Hour') }}</label>
                                <input type="number" min="1" max="100" class="form-control @error('homeToggleMaxPerHour') is-invalid @enderror" wire:model="homeToggleMaxPerHour">
                                @error('homeToggleMaxPerHour') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-transparent py-3">
                        <h5 class="card-title mb-0">{{ __('Maintenance Mode') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-0">
                            <label class="form-label small fw-bold">{{ __('Maintenance Notice Message') }}</label>
                            <textarea class="form-control @error('maintenanceMessage') is-invalid @enderror" rows="3" wire:model="maintenanceMessage" placeholder="{{ __('Leave blank for default system message...') }}"></textarea>
                            <div class="form-text text-muted">
                                {{ __('Displays to visitors when the application is placed into maintenance mode.') }}
                            </div>
                            @error('maintenanceMessage') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <!-- Audit Reason & Save Action -->
            <div class="col-lg-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="row align-items-center g-3">
                            <div class="col-md-8">
                                <label class="form-label small fw-bold mb-1">{{ __('Audit Reason (Required)') }}</label>
                                <input type="text" class="form-control @error('reason') is-invalid @enderror" wire:model="reason" placeholder="{{ __('State why you are updating site settings...') }}" required>
                                @error('reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4 text-md-end pt-md-3">
                                <button type="submit" class="btn btn-primary px-4" dusk="save-site-settings-btn">
                                    <i class="fa fa-save me-1" aria-hidden="true"></i> {{ __('Save Site Settings') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

