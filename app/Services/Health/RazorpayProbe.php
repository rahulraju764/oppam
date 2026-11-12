<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;
use App\Contracts\PaymentGateway;
use App\Data\Settings\IntegrationStatusData;
use App\Models\AdminUser;
use Throwable;

/** Razorpay (M10): which gateway, test or live keys, and a read-only "list payments of the last minute" call. */
final class RazorpayProbe implements IntegrationProbe
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function key(): string
    {
        return 'razorpay';
    }

    public function label(): string
    {
        return __('Payments (Razorpay)');
    }

    public function facts(): array
    {
        return [
            __('Driver') => (string) config('oppam.payments.driver'),
            __('Mode') => $this->gateway->isTestMode() ? __('Test keys') : __('Live keys'),
            __('Key id') => Probes::mask($this->gateway->publicKey()),
        ];
    }

    public function check(AdminUser $admin): IntegrationStatusData
    {
        if (config('oppam.payments.driver') !== 'razorpay') {
            return IntegrationStatusData::info(__('The fake payment gateway is in use (local / testing). No real payments are taken.'));
        }
        if (! $this->gateway->isConfigured()) {
            return IntegrationStatusData::error(__('Razorpay keys are missing for the current mode.'));
        }

        try {
            $this->gateway->paymentsBetween(now()->subMinute(), now());
        } catch (Throwable $e) {
            report($e);

            return IntegrationStatusData::error(__('Razorpay refused the request — check the keys for the current mode.'));
        }

        return IntegrationStatusData::ok(__('Razorpay answered with these keys (:mode).', ['mode' => $this->gateway->isTestMode() ? __('test') : __('live')]));
    }
}
