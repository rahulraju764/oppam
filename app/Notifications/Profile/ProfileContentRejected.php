<?php

declare(strict_types=1);

namespace App\Notifications\Profile;

use App\Enums\RejectReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A photo, caption or edited text was not approved (A04 photo / edited-fields queues). The
 * profile itself stays live. Mail for now (P3.2 adds in-app + live).
 */
final class ProfileContentRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $what,
        public readonly RejectReason $reason,
        public readonly ?string $note,
    ) {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('An update to your profile was not approved'))
            ->line(__('Your :what was not approved.', ['what' => $this->what]))
            ->line($this->reason->memberMessage());

        if (filled($this->note)) {
            $mail->line(__('Reviewer’s note: :note', ['note' => $this->note]));
        }

        return $mail->action(__('View my profile'), route('member.profile.me'));
    }
}
