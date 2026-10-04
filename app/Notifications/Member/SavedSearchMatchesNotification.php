<?php

declare(strict_types=1);

namespace App\Notifications\Member;

use App\Models\SavedSearch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Email alert for new matches matching a member's saved search (PRD §10 M04).
 * Carries the new matches count, a direct link to the search, and a signed 1-click unsubscribe link.
 */
final class SavedSearchMatchesNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        $searchUrl = route('member.search', ['f' => $this->savedSearch->filters]);
        $unsubscribeUrl = URL::signedRoute('saved-searches.unsubscribe', ['savedSearch' => $this->savedSearch->id]);

        return (new MailMessage)
            ->subject(__(':count new matches for ":name" | Oppam Matrimony', [
                'count' => $this->newMatchesCount,
                'name' => $this->savedSearch->name,
            ]))
            ->greeting(__('Hello!'))
            ->line(__('We found :count new profiles matching your saved search ":name".', [
                'count' => $this->newMatchesCount,
                'name' => $this->savedSearch->name,
            ]))
            ->action(__('View matches'), $searchUrl)
            ->line(__('You are receiving this email because you enabled :frequency alerts for this search.', [
                'frequency' => mb_strtolower($this->savedSearch->alert_frequency->label()),
            ]))
            ->line(__('To stop receiving alerts for this search, [unsubscribe here](:url).', ['url' => $unsubscribeUrl]));
    }
}
