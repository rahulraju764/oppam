<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BreakGlassGrant;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * A member's chat data-access log (PRD A13 "member-visible in data-access log on request", DPDP):
 * every break-glass read of a conversation the member is in — when, which conversation (the other
 * member's code), the reference and the reason, how long, and which staff account. Run by the team
 * answering a member's request; what is shared with the member is their decision (the staff
 * account is for internal follow-up). The full trail is in the audit log (chat.break_glass.*).
 */
final class ChatDataAccessLog extends Command
{
    protected $signature = 'chat:data-access-log {code : the member\'s profile code, e.g. OPM12370}';

    protected $description = 'List every break-glass read of a member\'s conversations (A13 / DPDP data-access log)';

    public function handle(): int
    {
        $code = strtoupper(trim((string) $this->argument('code')));
        $profile = preg_match('/^OPM\d{1,10}$/', $code) === 1 ? Profile::withTrashed()->where('code', $code)->first() : null;
        if ($profile === null) {
            $this->error('No member with that code.');

            return self::FAILURE;
        }

        $threads = ConversationParticipant::query()->where('profile_id', $profile->id)->pluck('conversation_id')->all();
        $others = ConversationParticipant::query()->whereIn('conversation_id', $threads)->where('profile_id', '!=', $profile->id)
            ->pluck('profile_id', 'conversation_id');
        $codes = Profile::withTrashed()->whereKey($others->values()->all())->pluck('code', 'id');
        $zone = (string) config('oppam.display_timezone');

        $rows = BreakGlassGrant::query()->whereIn('conversation_id', $threads)->with('admin:id,email')->orderBy('created_at')->get()
            ->map(fn (BreakGlassGrant $grant): array => [
                $grant->created_at?->setTimezone($zone)->format('Y-m-d H:i').' IST',
                $codes[(string) $others->get($grant->conversation_id)] ?? '—',
                $grant->reference_type->label().': '.$grant->reference,
                $grant->reason,
                ($grant->ended_at ?? $grant->expires_at)->setTimezone($zone)->format('H:i'),
                $grant->admin->email ?? '—',
            ])->all();

        if ($rows === []) {
            $this->info("No conversation of {$code} has been read by staff.");

            return self::SUCCESS;
        }

        $this->table(['Read at', 'Conversation with', 'Reference', 'Reason', 'Until', 'Staff account (internal)'], $rows);

        return self::SUCCESS;
    }
}
