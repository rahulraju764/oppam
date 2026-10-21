<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Actions\Admin\Members\SuspendMember;
use App\Domain\Engagement\EngagementSignals;
use App\Enums\EngagementSignalDecision;
use App\Enums\EngagementSignalStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Engagement\SignalUnavailable;
use App\Models\AdminUser;
use App\Models\EngagementSignal;
use App\Models\MemberRestriction;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\EngagementWarning;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A moderator's one-click decision on an A13 abuse signal (PRD A13 "each creates a signal row with
 * one-click actions (rate-limit, warn, suspend)"). Needs `chat.view_flagged` plus the decision's own
 * permission (EngagementSignalDecision::permission). Only an OPEN signal (else 409).
 *  - Dismiss: nothing happens to the member.
 *  - Pause sending: a MemberRestriction for 1 h / 24 h / 7 days — likes, favourites, interests,
 *    requests and chat messages are refused until then (a running pause is extended, never shortened).
 *  - Warn: the fixed-wording EngagementWarning (in-app + email).
 *  - Suspend: the A03 flow (SuspendMember: typed reason, sessions end, audited).
 * Optional note (≤ 500; the suspension reason, ≥ 5, when suspending). Audited ("chat.signal.decided");
 * the "Abuse signals" badge follows live.
 */
final class DecideEngagementSignal
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SuspendMember $suspend,
        private readonly EngagementSignals $signals,
    ) {}

    /**
     * @param  int|null  $hours  the pause length (EngagementSignalDecision::RATE_LIMIT_HOURS) when pausing
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws SignalUnavailable
     * @throws MemberStateConflict the member is already suspended / deleted
     */
    public function handle(AdminUser $admin, EngagementSignal $signal, EngagementSignalDecision $decision, ?int $hours = null, ?string $note = null): void
    {
        Gate::forUser($admin)->authorize('chat.view_flagged');
        Gate::forUser($admin)->authorize($decision->permission());

        $note = $note === null || trim($note) === '' ? null : trim($note);
        Validator::make(['note' => $note, 'hours' => $hours], [
            'note' => array_filter(['nullable', 'string', 'max:500', $decision === EngagementSignalDecision::Suspend ? 'required' : null, $decision === EngagementSignalDecision::Suspend ? 'min:5' : null]),
            'hours' => $decision === EngagementSignalDecision::RateLimit ? ['required', 'integer', Rule::in(EngagementSignalDecision::RATE_LIMIT_HOURS)] : ['nullable'],
        ], ['note.required' => __('Type the reason for the suspension.'), 'hours.in' => __('Choose how long to pause sending.')],
            ['note' => __('note'), 'hours' => __('pause length')])->validate();

        $warn = DB::transaction(function () use ($admin, $signal, $decision, $hours, $note): ?User {
            $locked = EngagementSignal::query()->whereKey($signal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== EngagementSignalStatus::Open) {
                throw SignalUnavailable::decided();
            }

            $profile = Profile::withTrashed()->find($locked->profile_id);
            $warn = null;
            $until = null;

            match ($decision) {
                EngagementSignalDecision::RateLimit => $until = $this->pause($admin, $locked, (int) $hours, $note),
                EngagementSignalDecision::Warn => $warn = $profile?->user,
                EngagementSignalDecision::Suspend => $this->suspend->handle($admin, $profile->user ?? throw MemberStateConflict::notActive(), (string) $note),
                EngagementSignalDecision::Dismiss => null,
            };

            $locked->status = $decision->resultingStatus();
            $locked->decision = $decision;
            $locked->decision_note = $note;
            $locked->decided_by_admin_id = $admin->id;
            $locked->decided_at = now();
            $locked->save();

            $this->audit->record('chat.signal.decided', $locked, before: ['status' => 'OPEN'], after: array_filter([
                'decision' => $decision->value,
                'type' => $locked->type->value,
                'paused_until' => $until,
            ]), reason: $note, actor: $admin, subjectLabel: $profile?->code);

            $this->signals->announce();

            return $warn;
        });

        $warn?->notify(new EngagementWarning);
    }

    /** @return string when the pause ends (ISO-8601) */
    private function pause(AdminUser $admin, EngagementSignal $signal, int $hours, ?string $note): string
    {
        $until = now()->addHours($hours);
        $running = MemberRestriction::query()->where('profile_id', $signal->profile_id)->active()->lockForUpdate()->orderByDesc('until')->first();

        if ($running !== null && $running->until->gte($until)) {
            return $running->until->toIso8601String();   // never shortened
        }

        $restriction = new MemberRestriction;
        $restriction->profile_id = $signal->profile_id;
        $restriction->until = $until;
        $restriction->reason = $note ?? $signal->type->label();
        $restriction->signal_id = $signal->id;
        $restriction->created_by_admin_id = $admin->id;
        $restriction->save();

        return $until->toIso8601String();
    }
}
