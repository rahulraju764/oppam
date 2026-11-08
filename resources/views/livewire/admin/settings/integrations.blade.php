<div>
    <div class="admin-page-head d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('Third-Party Integrations') }}</h1>
            <p class="text-muted mb-0">{{ __('External service connections and health diagnostics (PRD §11 A15). Credentials and secrets are managed via server environment.') }}</p>
        </div>
    </div>

    <div class="row g-4">
        <!-- Razorpay Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa fa-credit-card fa-lg text-primary"></i>
                            <h5 class="card-title mb-0">{{ __('Razorpay') }}</h5>
                        </div>
                        @if ($integrations['razorpay']['isLive'])
                            <span class="badge bg-danger">{{ __('Live Mode') }}</span>
                        @else
                            <span class="badge bg-warning text-dark">{{ __('Test Mode') }}</span>
                        @endif
                    </div>
                    <ul class="list-unstyled small mb-3">
                        <li class="mb-1"><span class="text-muted">{{ __('Driver:') }}</span> <code>{{ $integrations['razorpay']['driver'] }}</code></li>
                        <li class="mb-1"><span class="text-muted">{{ __('Key ID:') }}</span> <code>{{ $integrations['razorpay']['keyMasked'] }}</code></li>
                        <li><span class="text-muted">{{ __('Status:') }}</span> {{ $integrations['razorpay']['status'] }}</li>
                    </ul>

                    @if (isset($testResults['razorpay']))
                        <div class="alert alert-{{ $testResults['razorpay']['status'] }} py-2 small mb-3">
                            {{ $testResults['razorpay']['message'] }}
                        </div>
                    @endif

                    @can('settings.edit')
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" wire:click="testRazorpay" wire:loading.attr="disabled" dusk="test-razorpay-btn">
                            <span wire:loading.remove wire:target="testRazorpay"><i class="fa fa-play me-1"></i> {{ __('Test Credentials') }}</span>
                            <span wire:loading wire:target="testRazorpay"><i class="fa fa-spinner fa-spin me-1"></i> {{ __('Testing...') }}</span>
                        </button>
                    @endcan
                </div>
            </div>
        </div>

        <!-- MSG91 SMS Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa fa-commenting-o fa-lg text-success"></i>
                            <h5 class="card-title mb-0">{{ __('MSG91 (SMS)') }}</h5>
                        </div>
                        <span class="badge bg-light text-dark border">{{ $integrations['sms']['driver'] }}</span>
                    </div>
                    <ul class="list-unstyled small mb-3">
                        <li class="mb-1"><span class="text-muted">{{ __('Sender ID:') }}</span> <code>{{ $integrations['sms']['senderId'] }}</code></li>
                        <li class="mb-1"><span class="text-muted">{{ __('DLT Templates:') }}</span> {{ $integrations['sms']['templatesCount'] }} configured</li>
                        <li><span class="text-muted">{{ __('Auth Status:') }}</span> {{ $integrations['sms']['status'] }}</li>
                    </ul>

                    @if (isset($testResults['sms']))
                        <div class="alert alert-{{ $testResults['sms']['status'] }} py-2 small mb-3">
                            {{ $testResults['sms']['message'] }}
                        </div>
                    @endif

                    @can('settings.edit')
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" wire:click="testSms" wire:loading.attr="disabled" dusk="test-sms-btn">
                            <span wire:loading.remove wire:target="testSms"><i class="fa fa-play me-1"></i> {{ __('Test Gateway') }}</span>
                            <span wire:loading wire:target="testSms"><i class="fa fa-spinner fa-spin me-1"></i> {{ __('Testing...') }}</span>
                        </button>
                    @endcan
                </div>
            </div>
        </div>

        <!-- Mail Provider Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa fa-envelope fa-lg text-info"></i>
                            <h5 class="card-title mb-0">{{ __('Email Service') }}</h5>
                        </div>
                        <span class="badge bg-info text-white">{{ $integrations['email']['driver'] }}</span>
                    </div>
                    <ul class="list-unstyled small mb-3">
                        <li class="mb-1"><span class="text-muted">{{ __('From:') }}</span> <code>{{ $integrations['email']['from'] }}</code></li>
                        <li><span class="text-muted">{{ __('Default Mailer:') }}</span> <code>{{ $integrations['email']['driver'] }}</code></li>
                    </ul>

                    @if (isset($testResults['email']))
                        <div class="alert alert-{{ $testResults['email']['status'] }} py-2 small mb-3">
                            {{ $testResults['email']['message'] }}
                        </div>
                    @endif

                    @can('settings.edit')
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" wire:click="testEmail" wire:loading.attr="disabled" dusk="test-email-btn">
                            <span wire:loading.remove wire:target="testEmail"><i class="fa fa-paper-plane me-1"></i> {{ __('Send Test Email') }}</span>
                            <span wire:loading wire:target="testEmail"><i class="fa fa-spinner fa-spin me-1"></i> {{ __('Sending...') }}</span>
                        </button>
                    @endcan
                </div>
            </div>
        </div>

        <!-- Storage Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa fa-cloud-upload fa-lg text-secondary"></i>
                            <h5 class="card-title mb-0">{{ __('Storage & S3') }}</h5>
                        </div>
                        <span class="badge bg-secondary">{{ $integrations['storage']['driver'] }}</span>
                    </div>
                    <ul class="list-unstyled small mb-3">
                        <li class="mb-1"><span class="text-muted">{{ __('Default Disk:') }}</span> <code>{{ $integrations['storage']['driver'] }}</code></li>
                        <li><span class="text-muted">{{ __('S3 Bucket:') }}</span> <code>{{ $integrations['storage']['s3Bucket'] }}</code></li>
                    </ul>

                    @if (isset($testResults['storage']))
                        <div class="alert alert-{{ $testResults['storage']['status'] }} py-2 small mb-3">
                            {{ $testResults['storage']['message'] }}
                        </div>
                    @endif

                    @can('settings.edit')
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" wire:click="testStorage" wire:loading.attr="disabled" dusk="test-storage-btn">
                            <span wire:loading.remove wire:target="testStorage"><i class="fa fa-refresh me-1"></i> {{ __('Test Read / Write') }}</span>
                            <span wire:loading wire:target="testStorage"><i class="fa fa-spinner fa-spin me-1"></i> {{ __('Probing...') }}</span>
                        </button>
                    @endcan
                </div>
            </div>
        </div>

        <!-- Realtime & WebSockets Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa fa-bolt fa-lg text-warning"></i>
                            <h5 class="card-title mb-0">{{ __('Real-time Broadcast') }}</h5>
                        </div>
                        @if ($integrations['broadcast']['isRealtime'])
                            <span class="badge bg-success">{{ __('Realtime On') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ __('Realtime Off') }}</span>
                        @endif
                    </div>
                    <ul class="list-unstyled small mb-3">
                        <li class="mb-1"><span class="text-muted">{{ __('Driver:') }}</span> <code>{{ $integrations['broadcast']['driver'] }}</code></li>
                        <li><span class="text-muted">{{ __('Connection Status:') }}</span> {{ $integrations['broadcast']['status'] }}</li>
                    </ul>

                    @if (isset($testResults['broadcast']))
                        <div class="alert alert-{{ $testResults['broadcast']['status'] }} py-2 small mb-3">
                            {{ $testResults['broadcast']['message'] }}
                        </div>
                    @endif

                    @can('settings.edit')
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" wire:click="testBroadcast" wire:loading.attr="disabled" dusk="test-broadcast-btn">
                            <span wire:loading.remove wire:target="testBroadcast"><i class="fa fa-signal me-1"></i> {{ __('Test Driver Ping') }}</span>
                            <span wire:loading wire:target="testBroadcast"><i class="fa fa-spinner fa-spin me-1"></i> {{ __('Testing...') }}</span>
                        </button>
                    @endcan
                </div>
            </div>
        </div>

        <!-- Turnstile Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa fa-shield fa-lg text-dark"></i>
                            <h5 class="card-title mb-0">{{ __('Cloudflare Turnstile') }}</h5>
                        </div>
                        <span class="badge bg-light text-dark border">{{ $integrations['turnstile']['status'] }}</span>
                    </div>
                    <ul class="list-unstyled small mb-3">
                        <li class="mb-1"><span class="text-muted">{{ __('Site Key:') }}</span> <code>{{ $integrations['turnstile']['siteKeyMasked'] }}</code></li>
                        <li><span class="text-muted">{{ __('Protection:') }}</span> Public contact & login forms</li>
                    </ul>

                    @if (isset($testResults['turnstile']))
                        <div class="alert alert-{{ $testResults['turnstile']['status'] }} py-2 small mb-3">
                            {{ $testResults['turnstile']['message'] }}
                        </div>
                    @endif

                    @can('settings.edit')
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" wire:click="testTurnstile" wire:loading.attr="disabled" dusk="test-turnstile-btn">
                            <span wire:loading.remove wire:target="testTurnstile"><i class="fa fa-check me-1"></i> {{ __('Verify Config') }}</span>
                            <span wire:loading wire:target="testTurnstile"><i class="fa fa-spinner fa-spin me-1"></i> {{ __('Checking...') }}</span>
                        </button>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</div>

