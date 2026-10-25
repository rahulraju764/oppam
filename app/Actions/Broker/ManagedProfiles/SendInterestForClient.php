<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Actions\Engagement\SendInterest;
use App\Data\Engagement\InterestResult;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\BureauInterestAllowance;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\BureauPermission;
use App\Enums\ProfileStatus;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Broker;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Client Inbox — send an interest for a managed client (§11A B.6, R-M13-35; decisions 2026-10-25):
 * staff type the profile code the family found and choose a ready message. `client_inbox.respond`,
 * the client inside the caller's ManagedProfileScope (else 404) and ACTIVE, the target one the
 * client could see (ACTIVE, opposite gender, not blocked — else "no profile with this code").
 * Then exactly the member path (SendInterest::send: contact filter, cooldown, a crossed interest
 * is accepted), charged to the bureau's monthly allowance instead of a plan. Audited with the
 * individual staff user.
 */
final class SendInterestForClient
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly ProfileVisibility $visibility,
        private readonly SendInterest $send,
        private readonly BureauInterestAllowance $allowance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<Profile> the client isn't in the caller's scope
     * @throws ManagedProfileRefused client not ACTIVE, unknown target, allowance used up
     * @throws EngagementRefused contact filter, cooldown, already connected, too fast
     * @throws ValidationException code format, template
     */
    public function handle(User $actor, string $clientCode, string $targetCode, ?string $templateCode): InterestResult
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ClientInboxRespond);
        $client = $this->scope->find($staff, $clientCode);
        /** @var Broker $broker */
        $broker = $staff->broker;

        $targetCode = strtoupper(trim($targetCode));
        if (preg_match('/^OPM\d{1,10}$/', $targetCode) !== 1) {
            throw ValidationException::withMessages(['target' => __('Enter a profile code like OPM12345.')]);
        }

        if ($client->status !== ProfileStatus::Active) {
            throw ManagedProfileRefused::clientNotActive();
        }

        $target = Profile::query()->where('code', $targetCode)->first();
        if ($target === null || ! $this->visibility->profileCanSee($client, $target)) {
            throw ManagedProfileRefused::unknownTarget();
        }

        $result = $this->send->send($client, $target, $templateCode, null,
            fn () => $this->allowance->consume($broker));

        if ($result->changed) {
            $this->audit->record('broker.interest_sent', $client, after: [
                'broker' => $broker->code, 'to' => $target->code, 'connected' => $result->connected,
            ], actor: $actor, subjectLabel: $client->code);
        }

        return $result;
    }
}
