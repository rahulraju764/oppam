<?php

declare(strict_types=1);

namespace App\Events\Account;

use App\Models\Announcement;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live announcement broadcast to online members (PRD §11 A14).
 */
final class AdminAnnouncementBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Announcement $announcement) {}

    public function broadcastOn(): Channel
    {
        return new PresenceChannel('presence.online');
    }

    public function broadcastAs(): string
    {
        return 'announcement.broadcast';
    }

    /** @return array{id: string, title: string, body: string, action_url: string|null, action_text: string|null, is_dismissible: bool} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'body' => $this->announcement->body,
            'action_url' => $this->announcement->action_url,
            'action_text' => $this->announcement->action_text,
            'is_dismissible' => $this->announcement->is_dismissible,
        ];
    }
}
