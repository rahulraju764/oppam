<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauPermission;
use App\Models\BrokerClientNote;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tick off a note's follow-up (§11A B.6): `client_notes.write`; the note must belong to the
 * caller's bureau AND to a profile inside its ManagedProfileScope, else 404. Idempotent.
 */
final class CompleteFollowUp
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
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
            ->firstOrFail();

        if ($note->follow_up_done_at === null) {
            $note->forceFill(['follow_up_done_at' => now()])->save();
        }

        return $note;
    }
}
