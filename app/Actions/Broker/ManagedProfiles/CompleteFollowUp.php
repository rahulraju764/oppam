<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauPermission;
use App\Models\BrokerClientNote;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tick off a note's follow-up (§11A B.6): `client_notes.write`; the note must belong to the
 * caller's bureau AND to a profile inside its ManagedProfileScope, else 404. Idempotent. Audited
 * (once, by whoever ticked it) — the staff performance report counts follow-ups completed (P7.5).
 */
final class CompleteFollowUp
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<BrokerClientNote>
     */
    public function handle(User $actor, string $noteId): BrokerClientNote
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ClientNotesWrite);

        $note = BrokerClientNote::query()
            ->whereKey($noteId)
            ->where('broker_id', $staff->broker_id)
            ->whereNotNull('follow_up_on')
            ->whereIn('profile_id', $this->scope->query($staff)->select('profiles.id'))
            ->with('profile:id,code')
            ->firstOrFail();

        if ($note->follow_up_done_at === null) {
            $note->forceFill(['follow_up_done_at' => now()])->save();
            $this->audit->record('broker.follow_up_completed', $note, after: ['broker' => $staff->broker?->code],
                actor: $actor, subjectLabel: $note->profile?->code);
        }

        return $note;
    }
}
