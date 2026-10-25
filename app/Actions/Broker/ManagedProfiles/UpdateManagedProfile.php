<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\BureauPermission;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\ValueObjects\PhoneNumber;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The bureau-only field of a managed profile (§11A B.6 "edit any field", B.17): the client mobile
 * — the future claim key — with the same checks as at creation (E.164, on no user and no other
 * profile, not banned; rows locked). The M02 sections are saved by their own wizard Actions,
 * which authorize the bureau through ProfilePolicy (`profiles.edit` + ManagedProfileScope).
 */
final class UpdateManagedProfile
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly BanList $bans,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<Profile>
     * @throws ValidationException
     */
    public function handle(User $actor, string $code, string $clientPhone): Profile
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ProfilesEdit);

        $phone = PhoneNumber::tryParse($clientPhone)
            ?? throw ValidationException::withMessages(['clientPhone' => __('Enter a valid mobile number (an Indian number, or start with + for abroad).')]);

        return DB::transaction(function () use ($actor, $staff, $code, $phone): Profile {
            $profile = $this->scope->query($staff)->where('profiles.code', $code)->lockForUpdate()->firstOrFail();

            if ($profile->client_phone === $phone->e164()) {
                return $profile;
            }

            $taken = User::withTrashed()->where('phone', $phone->e164())->lockForUpdate()->exists()
                || Profile::withTrashed()->where('client_phone', $phone->e164())->whereKeyNot($profile->id)->lockForUpdate()->exists()
                || $this->bans->isBanned(BannedIdentifierType::Phone, $phone->e164());

            if ($taken) {
                throw ValidationException::withMessages(['clientPhone' => CreateManagedProfile::phoneTakenMessage()]);
            }

            $before = $profile->client_phone !== null ? PhoneNumber::tryParse($profile->client_phone)?->masked() : null;
            $profile->forceFill(['client_phone' => $phone->e164()])->save();

            // Masked only: audit rows never hold a full mobile.
            $this->audit->record('broker.profile_client_phone_changed', $profile, ['client_phone' => $before],
                ['client_phone' => $phone->masked()], actor: $actor, subjectLabel: $profile->code);

            return $profile;
        });
    }
}
