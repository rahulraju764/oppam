<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Our support team replied to a member's ticket (PRD A10): in-app (live) and by email, linking to
 * the ticket. The reply text is in the ticket, not in the notification.
 */
final class TicketReplied extends MemberNotification
{
    public function __construct(public readonly string $ticketCode) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('We replied to your request :code', ['code' => $this->ticketCode]),
            body: __('Open it to read our reply and answer if you need to.'),
            url: route('member.help.ticket', ['ticket' => $this->ticketCode]),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return $this->withPreferencesLink((new MailMessage)
            ->subject($content->title)
            ->line($content->body)
            ->action(__('Open my request'), (string) $content->url));
    }
}
