<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use App\Domain\Comms\CampaignLinks;
use App\Domain\Comms\TemplateCopy;
use App\Models\Campaign;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An A14 campaign test-send to the admin's own address (P8.3b1): the campaign's email exactly as a
 * member gets it, with "Anjali" for {first_name} and the button going straight to its page (no
 * tracked link, nothing counted). Sent at once — the admin waits for it.
 */
final class CampaignTestMessage extends Notification
{
    public function __construct(public readonly Campaign $campaign) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fill = fn (string $text): string => str_replace('{first_name}', 'Anjali', $text);

        $mail = (new MailMessage)->subject('[TEST] '.$fill((string) $this->campaign->subject));
        foreach (preg_split('/\R{2,}/', trim($fill($this->campaign->message))) ?: [] as $paragraph) {
            $mail->line(TemplateCopy::markdown(trim($paragraph)));
        }
        if ($this->campaign->action_url !== null) {
            $mail->action((string) $this->campaign->action_text, CampaignLinks::destination($this->campaign));
        }

        return $mail->line(__('Test of campaign :code. Members also get an unsubscribe link and the notification-settings link here.', ['code' => $this->campaign->code]));
    }
}
