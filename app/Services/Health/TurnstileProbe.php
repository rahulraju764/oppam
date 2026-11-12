<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;
use App\Data\Settings\IntegrationStatusData;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile (contact form, P8.1b). The check verifies a dummy token: Cloudflare answering
 * "invalid response" proves it is reachable and the secret is accepted; "invalid secret" means not.
 */
final class TurnstileProbe implements IntegrationProbe
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function key(): string
    {
        return 'turnstile';
    }

    public function label(): string
    {
        return __('Bot check (Turnstile)');
    }

    public function facts(): array
    {
        return [
            __('Driver') => (string) config('oppam.captcha.driver'),
            __('Site key') => Probes::mask(config('services.turnstile.site_key')),
        ];
    }

    public function check(AdminUser $admin): IntegrationStatusData
    {
        if (config('oppam.captcha.driver') !== 'turnstile') {
            return IntegrationStatusData::info(__('The bot check is off here (driver ":driver").', ['driver' => config('oppam.captcha.driver')]));
        }
        $secret = (string) config('services.turnstile.secret_key');
        if ($secret === '') {
            return IntegrationStatusData::error(__('The Turnstile secret key is missing.'));
        }

        try {
            $codes = (array) Http::asForm()->timeout(8)->post(self::VERIFY_URL, ['secret' => $secret, 'response' => 'integration-probe'])->json('error-codes', []);
        } catch (Throwable $e) {
            report($e);

            return IntegrationStatusData::error(__('Cloudflare could not be reached.'));
        }

        return in_array('invalid-input-secret', $codes, true)
            ? IntegrationStatusData::error(__('Cloudflare refused the secret key.'))
            : IntegrationStatusData::ok(__('Cloudflare answered and accepted the secret key.'));
    }
}
