<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauPermission;
use App\Enums\BureauStaffStatus;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Assign managed clients to a staff member, one or many at once (§11A B.6, `profiles.assign`), or
 * clear the assignment. The assignee must be an ACTIVE login of the caller's own bureau (looked up
 * by broker id from the staff row — an id from another bureau is "not found"); every code must
 * resolve inside ManagedProfileScope or nothing changes (404). Up to MAX codes per call; audited.
 */
final class AssignManagedProfiles
{
    public const MAX = 100;

    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $codes
     * @return int how many profiles changed
     *
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<Profile>
     * @throws ManagedProfileRefused
     * @throws ValidationException
     */
    public function handle(User $actor, array $codes, ?string $assigneeId): int
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ProfilesAssign);
        $codes = array_values(array_unique($codes));

        Validator::make(['codes' => $codes], [
            'codes' => ['required', 'array', 'max:'.self::MAX],
            'codes.*' => ['required', 'string', 'max:12'],
        ], ['codes.required' => __('Choose at least one profile.')])->validate();

        return DB::transaction(function () use ($actor, $staff, $codes, $assigneeId): int {
            $assignee = null;
            if ($assigneeId !== null) {
                $assignee = BrokerStaff::query()->whereKey($assigneeId)->where('broker_id', $staff->broker_id)
                    ->where('status', BureauStaffStatus::Active)->first() ?? throw ManagedProfileRefused::invalidAssignee();
            }

            $profiles = $this->scope->query($staff)->whereIn('profiles.code', $codes)->lockForUpdate()->get();
            if ($profiles->count() !== count($codes)) {
                throw (new ModelNotFoundException)->setModel(Profile::class);
            }

            $changed = $profiles->filter(fn (Profile $profile): bool => $profile->assigned_staff_id !== $assignee?->id);
            Profile::query()->whereKey($changed->modelKeys())->update(['assigned_staff_id' => $assignee?->id]);

            if ($changed->isNotEmpty()) {
                $this->audit->record('broker.profiles_assigned', $assignee, after: [
                    'profiles' => $changed->pluck('code')->all(), 'assigned' => $assignee !== null,
                ], actor: $actor);
            }

            return $changed->count();
        });
    }
}
