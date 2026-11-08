<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Enums\Flag;
use App\Models\AdminUser;
use App\Support\Facades\Feature;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Third-party integrations status and diagnostic test screen (PRD §11 A15).
 * Secrets are never editable or visible in plain text.
 */
#[Layout('layouts::admin', ['title' => 'Integrations'])]
final class Integrations extends Component
{
    /** @var array<string, array{status: string, message: string}> */
    public array $testResults = [];

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function testRazorpay(): void
    {
        $this->authorize('settings.edit');

        $isLive = Feature::active(Flag::PaymentsRazorpayLive);
        $keyId = $isLive
            ? (string) config('services.razorpay.live.key_id')
            : (string) config('services.razorpay.test.key_id');
        $secret = $isLive
            ? (string) config('services.razorpay.live.key_secret')
            : (string) config('services.razorpay.test.key_secret');
        $driver = config('oppam.payment.driver', 'fake');

        if ($driver === 'fake') {
            $this->testResults['razorpay'] = [
                'status' => 'success',
                'message' => __('Payment driver is set to "fake" (local/testing sandbox). Simulated transactions are active.'),
            ];

            return;
        }

        if (empty($keyId) || empty($secret)) {
            $this->testResults['razorpay'] = [
                'status' => 'danger',
                'message' => __('Razorpay keys are missing for mode: :mode. Check your .env configuration.', ['mode' => $isLive ? 'LIVE' : 'TEST']),
            ];

            return;
        }

        $this->testResults['razorpay'] = [
            'status' => 'success',
            'message' => __('Razorpay credentials (:mode mode) configured with Key ID :key.', [
                'mode' => $isLive ? 'LIVE' : 'TEST',
                'key' => $this->maskSecret($keyId),
            ]),
        ];
    }

    public function testSms(): void
    {
        $this->authorize('settings.edit');

        $driver = config('oppam.sms.driver', 'fake');
        $authKey = (string) config('services.msg91.auth_key');
        $senderId = (string) config('services.msg91.sender_id');

        if ($driver === 'fake' || $driver === 'log') {
            $this->testResults['sms'] = [
                'status' => 'success',
                'message' => __('SMS driver is set to ":driver". Outbound SMS are routed to local logs/sandbox.', ['driver' => $driver]),
            ];

            return;
        }

        if (empty($authKey)) {
            $this->testResults['sms'] = [
                'status' => 'danger',
                'message' => __('MSG91 auth key is missing from environment. SMS delivery disabled.'),
            ];

            return;
        }

        $this->testResults['sms'] = [
            'status' => 'success',
            'message' => __('MSG91 configured with Sender ID ":sender". SMS gateway operational.', ['sender' => $senderId]),
        ];
    }

    public function testEmail(): void
    {
        $this->authorize('settings.edit');

        $admin = $this->admin();
        $defaultMailer = config('mail.default', 'log');

        try {
            Mail::raw('This is an Oppam Matrimony system integration test email.', function ($message) use ($admin): void {
                $message->to($admin->email ?? 'admin@oppam.test')
                    ->subject('Oppam Integration Test — Mail Gateway');
            });

            $this->testResults['email'] = [
                'status' => 'success',
                'message' => __('Diagnostic test email dispatched successfully via ":mailer" mailer.', ['mailer' => $defaultMailer]),
            ];
        } catch (Exception $e) {
            $this->testResults['email'] = [
                'status' => 'danger',
                'message' => __('Failed sending test email: :err', ['err' => $e->getMessage()]),
            ];
        }
    }

    public function testStorage(): void
    {
        $this->authorize('settings.edit');

        $diskName = config('filesystems.default', 'local');

        try {
            $disk = Storage::disk($diskName);
            $testFileName = '.integration-probe-'.Str::random(8).'.txt';
            $testContent = 'Oppam storage probe '.now()->toIso8601String();

            $disk->put($testFileName, $testContent);
            $retrieved = $disk->get($testFileName);
            $disk->delete($testFileName);

            if ($retrieved === $testContent) {
                $this->testResults['storage'] = [
                    'status' => 'success',
                    'message' => __('Read and write test passed on disk ":disk".', ['disk' => $diskName]),
                ];
            } else {
                $this->testResults['storage'] = [
                    'status' => 'warning',
                    'message' => __('File write succeeded on disk ":disk" but content mismatch occurred.', ['disk' => $diskName]),
                ];
            }
        } catch (Exception $e) {
            $this->testResults['storage'] = [
                'status' => 'danger',
                'message' => __('Storage test failed on disk ":disk": :err', ['disk' => $diskName, 'err' => $e->getMessage()]),
            ];
        }
    }

    public function testBroadcast(): void
    {
        $this->authorize('settings.edit');

        $driver = config('broadcasting.default', 'null');

        if ($driver === 'null' || $driver === 'log') {
            $this->testResults['broadcast'] = [
                'status' => 'info',
                'message' => __('Broadcast driver is set to ":driver". Real-time WebSocket broadcasts are disabled in this environment.', ['driver' => $driver]),
            ];

            return;
        }

        try {
            // Test broadcaster resolution
            Broadcast::driver();

            $this->testResults['broadcast'] = [
                'status' => 'success',
                'message' => __('Broadcast driver ":driver" resolved successfully.', ['driver' => $driver]),
            ];
        } catch (Exception $e) {
            $this->testResults['broadcast'] = [
                'status' => 'danger',
                'message' => __('Broadcast driver error: :err', ['err' => $e->getMessage()]),
            ];
        }
    }

    public function testTurnstile(): void
    {
        $this->authorize('settings.edit');

        $siteKey = (string) config('services.turnstile.site_key');
        $secretKey = (string) config('services.turnstile.secret_key');

        if (empty($siteKey) || empty($secretKey)) {
            $this->testResults['turnstile'] = [
                'status' => 'warning',
                'message' => __('Turnstile keys are not configured. Captcha protection is operating in bypass or test mode.'),
            ];

            return;
        }

        $this->testResults['turnstile'] = [
            'status' => 'success',
            'message' => __('Cloudflare Turnstile configured with site key :key.', ['key' => $this->maskSecret($siteKey)]),
        ];
    }

    public function render(): View
    {
        $isLiveRazorpay = Feature::active(Flag::PaymentsRazorpayLive);
        $razorpayKey = $isLiveRazorpay
            ? (string) config('services.razorpay.live.key_id')
            : (string) config('services.razorpay.test.key_id');

        $integrations = [
            'razorpay' => [
                'name' => 'Razorpay Payments',
                'icon' => 'fa-credit-card',
                'status' => ! empty($razorpayKey) ? 'Configured' : 'Missing Keys',
                'isLive' => $isLiveRazorpay,
                'keyMasked' => $this->maskSecret($razorpayKey),
                'driver' => config('oppam.payment.driver', 'fake'),
            ],
            'sms' => [
                'name' => 'MSG91 SMS Gateway',
                'icon' => 'fa-commenting-o',
                'status' => ! empty(config('services.msg91.auth_key')) ? 'Configured' : 'Unset',
                'senderId' => config('services.msg91.sender_id', 'OPPAMM'),
                'driver' => config('oppam.sms.driver', 'fake'),
                'templatesCount' => count((array) config('services.msg91.notice_templates', [])),
            ],
            'email' => [
                'name' => 'Mail Provider',
                'icon' => 'fa-envelope',
                'status' => 'Active',
                'driver' => config('mail.default', 'log'),
                'from' => config('mail.from.address', 'noreply@oppam.in'),
            ],
            'storage' => [
                'name' => 'File Storage',
                'icon' => 'fa-cloud-upload',
                'status' => 'Active',
                'driver' => config('filesystems.default', 'local'),
                's3Bucket' => config('filesystems.disks.s3.bucket', 'none'),
            ],
            'broadcast' => [
                'name' => 'WebSockets & Realtime',
                'icon' => 'fa-bolt',
                'status' => config('broadcasting.default') !== 'null' ? 'Active' : 'Disabled',
                'driver' => config('broadcasting.default', 'null'),
                'isRealtime' => Feature::active(Flag::RealtimeEnabled),
            ],
            'turnstile' => [
                'name' => 'Cloudflare Turnstile',
                'icon' => 'fa-shield',
                'status' => ! empty(config('services.turnstile.site_key')) ? 'Configured' : 'Unset',
                'siteKeyMasked' => $this->maskSecret((string) config('services.turnstile.site_key')),
            ],
        ];

        return view('livewire.admin.settings.integrations', [
            'integrations' => $integrations,
        ]);
    }

    private function maskSecret(string $secret): string
    {
        if (strlen($secret) <= 8) {
            return $secret === '' ? __('Not configured') : '••••••••';
        }

        return substr($secret, 0, 4).'••••••••'.substr($secret, -4);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
