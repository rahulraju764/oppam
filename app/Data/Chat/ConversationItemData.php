<?php

declare(strict_types=1);

namespace App\Data\Chat;

use Carbon\CarbonImmutable;

/**
 * One row of the member's conversation list (PRD M07 inbox, template messages.php `.msg-thread`):
 * the other member's code (the thread's address in the URL), the name and photo this member may
 * see, the last message's preview, its time in IST, the member's unread count and own flags.
 * Never a ULID. `preview` is null in an admin support session (message text is private).
 */
final readonly class ConversationItemData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $photoUrl,
        public ?string $preview,
        public bool $lastFromMe,
        public ?CarbonImmutable $at,
        public int $unread,
        public bool $archived,
        public bool $frozen,
        public bool $muted = false,
    ) {}

    /** "10:24" today, "Yesterday", the weekday this week, else "12 Oct" (template list times). */
    public function timeLabel(?CarbonImmutable $now = null): string
    {
        if ($this->at === null) {
            return '';
        }

        $now ??= CarbonImmutable::now($this->at->timezone);

        return match (true) {
            $this->at->isSameDay($now) => $this->at->format('H:i'),
            $this->at->isSameDay($now->subDay()) => __('Yesterday'),
            $this->at->greaterThan($now->subDays(6)->startOfDay()) => $this->at->translatedFormat('D'),
            default => $this->at->translatedFormat('j M'),
        };
    }
}
