<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;
use App\Data\Settings\IntegrationStatusData;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** MSG91 SMS (M01 / M08): driver, sender id, approved DLT templates, and a read-only balance call. */
final class Msg91Probe implements IntegrationProbe
{
    public const BALANCE_URL = 'https://control.msg91.com/api/balance.php';

    public function key(): string
    {
        return 'msg91';
    }

    public function label(): string
    {
        return __('SMS (MSG91)');
    }

    public function facts(): array
    {
        return [
            __('Driver') => (string) config('oppam.sms.driver'),
            __('Sender id') => (string) config('services.msg91.sender_id'),
            __('Auth key') => filled(config('services.msg91.auth_key')) ? __('Set') : __('Not set'),   // a secret: never even partly shown
            __('OTP template') => filled(config('services.msg91.otp_template_id')) ? __('Set') : __('Not set'),
            __('Notification templates') => (string) count((array) config('services.msg91.notice_templates', [])),
        ];
    }

    public function check(AdminUser $admin): IntegrationStatusData
    {
        if (config('oppam.sms.driver') !== 'msg91') {
            return IntegrationStatusData::info(__('SMS go to the log (driver ":driver"). Nothing is texted.', ['driver' => config('oppam.sms.driver')]));
        }
        $key = (string) config('services.msg91.auth_key');
        if ($key === '') {
            return IntegrationStatusData::error(__('The MSG91 auth key is missing.'));
        }

        try {
            // The key goes in a header (as Msg91Gateway sends it), never the URL: a failed request's
            // message carries the full URL, and URLs end up in proxy and provider access logs.
            $response = Http::timeout(8)->withHeaders(['authkey' => $key])->get(self::BALANCE_URL, ['type' => 4]);
        } catch (Throwable $e) {
            // Never report() the raw exception: its message may hold request details. The class is enough.
            Log::warning('A15 integration check: MSG91 unreachable', ['exception' => $e::class]);

            return IntegrationStatusData::error(__('MSG91 could not be reached.'));
        }

        $body = trim($response->body());

        return $response->successful() && is_numeric($body)
            ? IntegrationStatusData::ok(__('MSG91 answered. Transactional SMS balance: :n.', ['n' => $body]))
            : IntegrationStatusData::error(__('MSG91 refused the auth key.'));
    }
}
