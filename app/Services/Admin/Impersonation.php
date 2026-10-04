<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\ImpersonationSession;
use Illuminate\Contracts\Session\Session;

/**
 * Is THIS member-site request an admin impersonating the member (A01 / A03, P1.7b)? The member
 * session carries the impersonation_sessions id under SESSION_KEY (set only by the handoff);
 * EnsureImpersonationIsValid ends it at expiry. Restricted Actions call assertAllowed() —
 * the rule lives in the Action, not in the view.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonation.id';

    private ?ImpersonationSession $memo = null;

    private bool $resolved = false;

    public function __construct(private readonly Session $session) {}

    /** The running impersonation for this request, if any. */
    public function current(): ?ImpersonationSession
    {
        if ($this->resolved) {
            return $this->memo;
        }

        $this->resolved = true;
        $id = $this->session->get(self::SESSION_KEY);

        if (! is_string($id) || $id === '') {
            return $this->memo = null;
        }

        $running = ImpersonationSession::query()->whereKey($id)->first();

        return $this->memo = $running !== null && $running->isRunning() ? $running : null;
    }

    public function isActive(): bool
    {
        return $this->current() !== null;
    }

    /**
     * Refuse a restricted action while impersonating.
     *
     * @throws ImpersonationRestricted
     */
    public function assertAllowed(): void
    {
        if ($this->session->has(self::SESSION_KEY)) {
            throw ImpersonationRestricted::make();
        }
    }

    /** Forget what this request resolved (after the session changed). */
    public function forget(): void
    {
        $this->memo = null;
        $this->resolved = false;
    }
}
