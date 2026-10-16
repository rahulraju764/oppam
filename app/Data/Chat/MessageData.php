<?php

declare(strict_types=1);

namespace App\Data\Chat;

use Carbon\CarbonImmutable;

/**
 * One message as the thread shows it to one member (PRD M07): `mine` (the member wrote it),
 * `system` (the platform did), the text to show (SYSTEM translated, an unsent message replaced),
 * its time in IST and — on the member's own messages only — the tick: `sent` ✓, `delivered` ✓✓
 * or `read` blue ✓✓ (blue only when both members have read receipts on, R-M07-7). The id is the
 * message ULID: the thread's order and the client's de-dupe key, as in the live payload (§9.4).
 */
final readonly class MessageData
{
    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public function __construct(
        public string $id,
        public string $clientId,
        public bool $mine,
        public bool $system,
        public string $text,
        public CarbonImmutable $at,
        public ?string $tick,
    ) {}

    public function tickLabel(): string
    {
        return match ($this->tick) {
            self::READ => __('Read'),
            self::DELIVERED => __('Delivered'),
            self::SENT => __('Sent'),
            default => '',
        };
    }
}
