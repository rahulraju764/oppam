<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Models\AdminUser;
use App\Models\SupportTicket;
use App\Queries\Billing\CurrentMembership;

/**
 * Fills a canned response's variables for one ticket (PRD A10 "canned responses with variables"):
 * {first_name}, {name}, {code} (the member's profile code), {ticket}, {plan}, {agent} (the
 * admin's name). Unknown variables are left as they are, so the agent sees them before sending.
 * The result is plain text — it is escaped wherever it is shown.
 */
final class CannedResponseRenderer
{
    public const VARIABLES = ['{first_name}', '{name}', '{code}', '{ticket}', '{plan}', '{agent}'];

    public function __construct(private readonly CurrentMembership $membership) {}

    public function render(string $body, SupportTicket $ticket, AdminUser $agent): string
    {
        $profile = $ticket->user?->profile;
        $name = $profile !== null ? $profile->first_name : $ticket->name;
        $plan = $profile !== null ? ($this->membership->current($profile)?->plan->name ?? __('Free')) : __('no account');

        return strtr($body, [
            '{first_name}' => (string) strtok(trim((string) $name), ' '),
            '{name}' => $ticket->name,
            '{code}' => $profile->code ?? '—',
            '{ticket}' => $ticket->code,
            '{plan}' => $plan,
            '{agent}' => $agent->name,
        ]);
    }
}
