<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use App\Domain\Comms\TemplateCopy;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An A14 template test-send to the admin's own address (P8.3a). Sample values only — never a real
 * member's data. Sent at once (not queued): it carries nothing secret, and the admin waits for it.
 */
final class TemplateTestMessage extends Notification
{
    public function __construct(
        public readonly string $subject,
        public readonly string $markdown,
        public readonly int $version,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('[TEST v'.$this->version.'] '.$this->subject);
        foreach (preg_split('/\R{2,}/', trim($this->markdown)) ?: [] as $paragraph) {
            $mail->line(TemplateCopy::markdown(trim($paragraph)));
        }

        return $mail->line(__('This is a test of an Oppam template with sample values.'));
    }
}
