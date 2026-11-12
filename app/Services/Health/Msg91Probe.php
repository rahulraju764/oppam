<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;
use App\Data\Settings\IntegrationStatusData;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Http;
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
            __('Auth key') => Probes::mask(config('services.msg91.auth_key')),
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
            $response = Http::timeout(8)->get(self::BALANCE_URL, ['authkey' => $key, 'type' => 4]);
        } catch (Throwable $e) {
            report($e);

            return IntegrationStatusData::error(__('MSG91 could not be reached.'));
        }

        $body = trim($response->body());

        return $response->successful() && is_numeric($body)
            ? IntegrationStatusData::ok(__('MSG91 answered. Transactional SMS balance: :n.', ['n' => $body]))
            : IntegrationStatusData::error(__('MSG91 refused the auth key.'));
    }
}
