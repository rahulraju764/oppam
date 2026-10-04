<?php

declare(strict_types=1);

namespace App\Notifications\Member;

use App\Models\SavedSearch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * "N new profiles match your saved search" (M04, PRD §10 M08 `saved_search_alert`). Email only for
 * now — the in-app + live copies come with the notification system (P3.2). Carries a count and
 * links only: the search (normalised filters) and the token unsubscribe page, which is also the
 * RFC 8058 one-click target in the List-Unsubscribe headers.
 */
final class SavedSearchMatchesNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** notification_preferences.event (PRD §10 M08). */
    public const EVENT = 'saved_search_alert';

    /** The search was deleted before the mail went out: drop it instead of failing and retrying. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly SavedSearch $savedSearch,
        public readonly int $newMatchesCount,
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
        $unsubscribeUrl = route('saved-searches.unsubscribe', ['token' => $this->savedSearch->alert_token]);
        $replace = ['count' => $this->newMatchesCount, 'name' => $this->savedSearch->name];

        return (new MailMessage)
            ->subject(trans_choice(':count new profile for ":name"|:count new profiles for ":name"', $this->newMatchesCount, $replace).' – '.config('oppam.site.name'))
            ->greeting(__('Hello!'))
            ->line(trans_choice(':count new profile matches your saved search ":name".|:count new profiles match your saved search ":name".', $this->newMatchesCount, $replace))
            ->action(__('View matches'), $this->savedSearch->searchUrl())
            ->line(__('You are receiving this email because you turned on :frequency alerts for this search.', [
                'frequency' => mb_strtolower($this->savedSearch->alert_frequency->label()),
            ]))
            ->line(__('To stop these alerts, [turn them off here](:url).', ['url' => $unsubscribeUrl]))
            ->withSymfonyMessage(function (Email $message) use ($unsubscribeUrl): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
