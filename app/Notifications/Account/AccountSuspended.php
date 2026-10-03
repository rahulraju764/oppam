<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Enums\SettingKey;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account is suspended" (A03). Neutral on purpose (owner decision 2026-10-02): the admin's
 * internal reason is never sent; the member is pointed to support. Verified email only — the
 * Action checks User::canReceiveAccountMail() before notifying. In-app copy arrives with P3.2.
 */
final class AccountSuspended extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Oppam Matrimony account is suspended'))
            ->line(__('Your account has been suspended by our team, and your profile is no longer visible to other members.'))
            ->line(__('If you think this is a mistake or want to know more, please contact our support team at :email.', [
                'email' => Settings::string(SettingKey::SiteSupportEmail),
            ]));
    }
}
