<?php

declare(strict_types=1);

namespace App\Notifications\Chat;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use App\Notifications\Support\NotificationRouter;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The offline chat digest (PRD M07 send flow, M08 `new_message`: "offline digest (10 min)"): one
 * email for everything waiting, sent by SendChatDigests. EMAIL ONLY — the in-app side of a new
 * message is the chat badge and the toast, never a bell row — and only where the router allows
 * email for `new_message` (the member's preference; a VERIFIED address — so nobody until email
 * verification ships in P8.2, owner decision 2026-10-16). Names and counts only: message text never
 * leaves the site.
 */
final class NewMessagesDigest extends MemberNotification
{
    /** @param  list<array{name: string, count: int}>  $threads  first name of the other member, unread messages from them */
    public function __construct(public readonly array $threads) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::NewMessage;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return in_array('mail', app(NotificationRouter::class)->channels($notifiable, $this->event()), true) ? ['mail'] : [];
    }

    public function content(object $notifiable): NotificationContentData
    {
        $total = array_sum(array_column($this->threads, 'count'));

        return new NotificationContentData(
            title: trans_choice('You have :count new message|You have :count new messages', $total, ['count' => $total]),
            body: __('From :names', ['names' => implode(', ', array_column($this->threads, 'name'))]),
            url: route('member.messages'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);
        $mail = (new MailMessage)->subject($content->title.' – '.config('oppam.site.name'))->line($content->title.'.');

        foreach ($this->threads as $thread) {
            $mail->line(trans_choice(':name sent you :count message|:name sent you :count messages', $thread['count'], $thread));
        }

        return $this->withPreferencesLink($mail->action(__('Read and reply'), route('member.messages')));
    }
}
