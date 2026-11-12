<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;
use App\Data\Settings\IntegrationStatusData;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Mail provider (M08 / M14): mailer and sender; the check sends ONE message to the admin's own address. */
final class MailProbe implements IntegrationProbe
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return __('Email');
    }

    public function facts(): array
    {
        return [
            __('Mailer') => (string) config('mail.default'),
            __('From') => (string) config('mail.from.address'),
        ];
    }

    public function check(AdminUser $admin): IntegrationStatusData
    {
        try {
            Mail::raw(__('This is a test from :site Admin → Settings → Integrations. No action is needed.', ['site' => config('oppam.site.name')]),
                fn ($message) => $message->to($admin->email)->subject(__(':site — email test', ['site' => config('oppam.site.name')])));
        } catch (Throwable $e) {
            report($e);

            return IntegrationStatusData::error(__('The mail provider refused the message. Check the mail settings.'));
        }

        return IntegrationStatusData::ok(__('A test email was handed to ":mailer" for :email.', ['mailer' => config('mail.default'), 'email' => $admin->email]));
    }
}
