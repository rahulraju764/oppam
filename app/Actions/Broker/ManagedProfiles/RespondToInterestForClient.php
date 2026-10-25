<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\DeclineInterest;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\BureauPermission;
use App\Enums\ProfileStatus;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Client Inbox — accept or decline a received interest on the client's behalf, "after speaking to
 * the family" (§11A B.6): `client_inbox.respond` (Owner, Manager, Telecaller), the client inside
 * the caller's ManagedProfileScope (foreign / unassigned → 404), then exactly the member path
 * (AcceptInterest::accept / DeclineInterest::decline — row locks, conversation, the sender is
 * notified). Accepting needs the pair to still see each other, as for a member. Chat stays
 * disabled until the client claims the profile (R-M13-34, ChatAccess). Audited with the
 * individual staff user (R-M13-23).
 */
final class RespondToInterestForClient
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly ProfileVisibility $visibility,
        private readonly AcceptInterest $accept,
        private readonly DeclineInterest $decline,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<Profile> the client isn't in the caller's scope
     * @throws NotFoundHttpException no interest from that profile, or (accept) the pair may not see each other
     * @throws EngagementRefused answered, withdrawn or expired meanwhile
     * @throws ManagedProfileRefused the client is suspended
     */
    public function handle(User $actor, string $clientCode, string $senderCode, bool $accept): void
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ClientInboxRespond);
        $client = $this->scope->find($staff, $clientCode);

        if ($client->status === ProfileStatus::Suspended) {
            throw ManagedProfileRefused::wrongStatus();
        }

        $sender = Profile::withTrashed()->where('code', $senderCode)->first() ?? throw new NotFoundHttpException;

        if ($accept) {
            if (! $this->visibility->profileCanSee($client, $sender)) {
                throw new NotFoundHttpException;
            }

            $this->accept->accept($client, $sender);
        } else {
            $this->decline->decline($client, $sender);
        }

        $this->audit->record($accept ? 'broker.interest_accepted' : 'broker.interest_declined', $client, after: [
            'broker' => $staff->broker?->code, 'from' => $sender->code,
        ], actor: $actor, subjectLabel: $client->code);
    }
}
