<?php

declare(strict_types=1);

namespace App\Events\Account;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sign this member's open tabs out NOW (PRD §9.4 `.session.revoked`). The session has already been
 * revoked in the database (session epoch / impersonation row) — this only tells the browser to
 * leave, so the next request is signed out by the session middleware. Always sent through
 * LiveSignal::afterCommit(): after the revoking transaction commits, and best-effort (a broadcast
 * failure is reported, never fails the suspension / session end).
 *
 * scope ALL           — every tab of the member (suspend, delete, admin password reset).
 * scope IMPERSONATION — only a tab running an admin support session (ended from the admin panel);
 *                       the member's own tabs ignore it.
 */
final class ForceLogout implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const ALL = 'ALL';

    public const IMPERSONATION = 'IMPERSONATION';

    public function __construct(
        public readonly string $userId,
        public readonly string $scope = self::ALL,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'session.revoked';
    }

    /** @return array{scope: string} */
    public function broadcastWith(): array
    {
        return ['scope' => $this->scope];
    }
}
