<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Comms\TemplateCopy;
use App\Domain\Safety\BlockList;
use App\Enums\CommsChannel;
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

    /**
     * A14 resend (P8.3a): only this one channel ('mail' / SmsChannel::class), and the delivery-log row
     * it was resent from. Set by ResendFailedMessage; the send then goes through the normal queue, so
     * the block check (shouldSend), quiet hours (withDelay) and preferences all still apply.
     */
    public ?string $onlyChannel = null;

    public ?string $resentFromId = null;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = app(NotificationRouter::class)->channels($notifiable, $this->event());

        return $this->onlyChannel === null ? $channels : array_values(array_intersect($channels, [$this->onlyChannel]));
    }

    /** @return list<string> the router's channels, for the resend check */
    public function routedChannels(object $notifiable): array
    {
        return app(NotificationRouter::class)->channels($notifiable, $this->event());
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [InAppChannel::class => 'notifications', 'mail' => 'mail', SmsChannel::class => 'notifications'];
    }

    /** @return array<string, DateTimeInterface> quiet hours: SMS waits until they end (IST, A15) */
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

    /** @return array<string, mixed> the in-app row and live payload — with the A14 in-app copy applied (P8.3a) */
    public function toArray(object $notifiable): array
    {
        $content = app(TemplateCopy::class)->inApp($this->event()->value, $this->content($notifiable), $notifiable);

        return $content->toArray($this->event()->value, $this->event()->icon());
    }

    /**
     * The admin's A14 email copy for this event (P8.3a), or null to use toMail(). Sent by
     * TemplatedMailChannel. The body is Markdown (escaped — no raw HTML), the button opens the
     * notification's page, and the preferences link is always added (R-M08-3).
     */
    public function templatedMail(object $notifiable): ?MailMessage
    {
        $templates = app(TemplateCopy::class);
        $copy = $templates->live($this->event()->value, CommsChannel::Email);
        if ($copy === null) {
            return null;
        }

        $content = $this->content($notifiable);
        $subject = $templates->fill((string) ($copy['subject'] ?? $content->title), $content, $notifiable);
        $body = $templates->fill($copy['body'], $content, $notifiable);

        $mail = (new MailMessage)->subject(mb_substr($subject, 0, 200));
        foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $paragraph) {
            $mail->line(TemplateCopy::markdown(trim($paragraph)));
        }

        return $this->decorateTemplatedMail($this->withPreferencesLink($mail->action(__('Open'), $content->url)), $notifiable);
    }

    /**
     * What a notification must keep even when an admin rewrote its email (e.g. the saved-search
     * alert's own unsubscribe line and List-Unsubscribe header). Default: nothing to add.
     */
    protected function decorateTemplatedMail(MailMessage $mail, object $notifiable): MailMessage
    {
        return $mail;
    }

    /** The admin's DLT template id for this event's SMS (P8.3a), or null for the configured one. */
    public function templatedSmsId(): ?string
    {
        $copy = app(TemplateCopy::class)->live($this->event()->value, CommsChannel::Sms);

        return $copy !== null && filled($copy['dlt_template_id']) ? $copy['dlt_template_id'] : null;
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
        return $mail->line(__('Manage which notifications you get in your [notification settings](:url).', ['url' => route('member.settings', ['tab' => 'notifications'])]));
    }
}
