<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Safety\BlockList;
use App\Enums\NotificationEvent;
use App\Models\Profile;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for every member notification (PRD §10 M08). A subclass names its event and says what it
 * says (content()); this class does the rest the same way for all of them:
 *  - channels from NotificationRouter (preferences, mandatory events, quiet-hours SMS delay);
 *  - one payload for the database row and the live broadcast (codes + display text only), with
 *    `type` = the event (no PHP class names reach the browser);
 *  - R-M08-1: nothing is delivered about an actor in a blocked pair — re-checked at send time,
 *    so a block made after the event was queued still wins;
 *  - queued: in-app (row, then the live signal — InAppChannel) and SMS on `notifications`, email on `mail`.
 */
abstract class MemberNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function event(): NotificationEvent;

    abstract public function content(object $notifiable): NotificationContentData;

    /** The member whose action this announces (a like, an interest, a view), if any. */
    public function actorProfileId(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return app(NotificationRouter::class)->channels($notifiable, $this->event());
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [InAppChannel::class => 'notifications', 'mail' => 'mail', SmsChannel::class => 'notifications'];
    }

    /** @return array<string, DateTimeInterface> quiet hours: SMS waits until 07:00 IST */
    public function withDelay(object $notifiable, string $channel): array
    {
        $delay = app(NotificationRouter::class)->smsDelay();

        return $delay === null ? [] : [SmsChannel::class => $delay];
    }

    /** R-M08-1: never tell a member about something done by someone in a blocked pair with them. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $actorId = $this->actorProfileId();
        if ($actorId === null || ! $notifiable instanceof User) {
            return true;
        }

        $recipient = Profile::query()->where('user_id', $notifiable->id)->first();
        $actor = Profile::withTrashed()->find($actorId);

        return $recipient === null || $actor === null || ! app(BlockList::class)->isBlocked($recipient, $actor);
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event()->value;
    }

    public function broadcastType(): string
    {
        return $this->event()->value;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->content($notifiable)->toArray($this->event()->value, $this->event()->icon());
    }

    /** Sent by InAppChannel right after the row is written, inside that same job. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /** Default email: the title, the text, a button to the page; every mail links to the preferences (R-M08-3). */
    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return $this->withPreferencesLink(
            (new MailMessage)->subject($content->title.' – '.config('oppam.site.name'))
                ->line($content->body)
                ->action(__('Open'), $content->url)
        );
    }

    /**
     * SMS template variables, in the DLT template's order. Default: none (a fixed text).
     *
     * @return list<string>
     */
    public function smsVariables(object $notifiable): array
    {
        return [];
    }

    protected function withPreferencesLink(MailMessage $mail): MailMessage
    {
        return $mail->line(__('Manage which notifications you get on your [notifications page](:url).', ['url' => route('member.notifications')]));
    }
}
