<?php

declare(strict_types=1);

namespace App\Notifications\Comms;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Comms\CampaignLinks;
use App\Domain\Comms\TemplateCopy;
use App\Enums\CommsChannel;
use App\Enums\NotificationEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Support\InAppChannel;
use App\Notifications\Support\MemberNotification;
use App\Notifications\Support\SmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * One A14 campaign message to one member (P8.3b1). A member notification of the event
 * `admin_announcement` ("Offers and announcements"), so NotificationRouter decides as for any
 * notification: the member's preference for that event and channel, a verified email / phone.
 * Limited to the campaign's one channel ($onlyChannel). Sent by SendCampaignBatch with
 * sendNow() — the batch job is the throttle and holds SMS through quiet hours itself.
 *
 * Its copy is the campaign's own (frozen at submit): the A14 event templates don't reword it.
 * {first_name} is the only placeholder. Email: the button is the signed click link, and every mail
 * carries the one-click unsubscribe (link + RFC 8058 List-Unsubscribe headers) and the preferences
 * link. Never resendable from the delivery log (DeliveryRecorder keeps no copy of it).
 */
final class CampaignMessage extends MemberNotification
{
    public function __construct(
        public readonly Campaign $campaign,
        public readonly CampaignRecipient $recipient,
    ) {
        $this->onlyChannel = match ($campaign->channel) {
            CommsChannel::Email => 'mail',
            CommsChannel::Sms => SmsChannel::class,
            CommsChannel::InApp => InAppChannel::class,
        };
    }

    public function event(): NotificationEvent
    {
        return NotificationEvent::AdminAnnouncement;
    }

    public function content(object $notifiable): NotificationContentData
    {
        $text = $this->personalise($this->campaign->message, $notifiable);

        return new NotificationContentData(
            title: mb_substr($this->personalise((string) ($this->campaign->subject ?? $this->campaign->name), $notifiable), 0, 150),
            body: mb_substr($text, 0, 500),
            url: app(CampaignLinks::class)->click($this->campaign, $this->recipient) ?? route('member.dashboard'),
        );
    }

    /** The campaign's own copy: no A14 in-app template for the event applies. */
    public function toArray(object $notifiable): array
    {
        return $this->content($notifiable)->toArray($this->event()->value, $this->event()->icon());
    }

    public function templatedMail(object $notifiable): ?MailMessage
    {
        return null;
    }

    public function templatedSmsId(): ?string
    {
        return $this->campaign->dlt_template_id;
    }

    /** @return list<string> */
    public function smsVariables(object $notifiable): array
    {
        return str_contains($this->campaign->message, '{first_name}') ? [$this->firstName($notifiable)] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $links = app(CampaignLinks::class);
        $unsubscribe = $links->unsubscribe($this->campaign, $this->recipient);

        $mail = (new MailMessage)->subject(mb_substr($this->personalise((string) $this->campaign->subject, $notifiable), 0, 200));
        foreach (preg_split('/\R{2,}/', trim($this->personalise($this->campaign->message, $notifiable))) ?: [] as $paragraph) {
            $mail->line(TemplateCopy::markdown(trim($paragraph)));
        }

        $click = $links->click($this->campaign, $this->recipient);
        if ($click !== null) {
            $mail->action((string) $this->campaign->action_text, $click);
        }

        return $this->withPreferencesLink($mail)
            ->line(__('Don’t want offers like this by email? [Unsubscribe](:url).', ['url' => $unsubscribe]))
            ->withSymfonyMessage(function (Email $message) use ($unsubscribe): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    private function personalise(string $text, object $notifiable): string
    {
        return str_replace('{first_name}', $this->firstName($notifiable), $text);
    }

    private function firstName(object $notifiable): string
    {
        if (! $notifiable instanceof User) {
            return '';
        }

        return $notifiable->relationLoaded('profile')
            ? (string) $notifiable->profile?->first_name
            : (string) Profile::query()->where('user_id', $notifiable->id)->value('first_name');
    }
}
