<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Actions\Profile\SaveBasicDetails;
use App\Data\Broker\ManagedProfileData;
use App\Data\Profile\BasicDetailsData;
use App\Domain\Broker\BureauGate;
use App\Domain\Profile\ProfileRules;
use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\BureauPermission;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Models\BrokerReferral;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Profile\ProfileCodeGenerator;
use App\ValueObjects\PhoneNumber;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Add profile" (§11A B.6): the first "Save draft" of the bureau form creates the client's DRAFT
 * profile. Rules, all checked before anything is written (B.17 "422 before any write"):
 *  - `profiles.create` (ACTIVE + KYC-verified bureau, R-M13-10 / R-M13-21 → 403 BROKER_KYC_REQUIRED /
 *    BROKER_INACTIVE) and `profiles.edit` (the form's sections are saved by the M02 Actions);
 *  - consent confirmed (R-M13-11) — stamped with the individual staff user and time (R-M13-23);
 *  - the client mobile is E.164 and on no user and no other profile (the future claim key, locked
 *    rows); a number on the A10 ban list gets the same answer (decisions 2026-10-25);
 *  - the step-1 basics by the M02 rules (ProfileRules::basic), then saved by SaveBasicDetails.
 * The profile is BROKER_MANAGED by the caller's bureau (never from input), attributed to it with a
 * PENDING MANAGED ledger row (B.10 managed conversions), and assigned to its creator when the
 * creator only sees assigned profiles (decisions 2026-10-25). Capped per login per hour.
 */
final class CreateManagedProfile
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly ProfileCodeGenerator $codes,
        private readonly SaveBasicDetails $basics,
        private readonly BanList $bans,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws HttpException 403 without the permission / KYC
     * @throws ValidationException
     */
    public function handle(User $actor, ManagedProfileData $data, BasicDetailsData $basic): Profile
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ProfilesCreate);
        $this->gate->authorize($actor, BureauPermission::ProfilesEdit);

        $phone = $this->validate($data, $basic);

        $key = 'broker-profile-create:'.$actor->id;
        if ($this->limiter->tooManyAttempts($key, (int) config('oppam.broker.managed_profiles_per_hour'))) {
            throw ValidationException::withMessages(['clientPhone' => __('You have added many profiles in the last hour. Please try again a little later.')]);
        }

        $profile = DB::transaction(function () use ($actor, $staff, $phone, $basic): Profile {
            $this->assertPhoneFree($phone);

            $profile = $this->insert($actor, $staff, $phone, $basic);
            $this->basics->handle($actor, $profile, $basic);

            $this->audit->record('broker.profile_created', $profile, after: [
                'broker' => $staff->broker?->code, 'assigned' => $profile->assigned_staff_id !== null,
            ], actor: $actor, subjectLabel: $profile->code);

            return $profile;
        });

        $this->limiter->hit($key, 3600);

        return $profile->refresh();
    }

    /** @throws ValidationException */
    private function validate(ManagedProfileData $data, BasicDetailsData $basic): PhoneNumber
    {
        $phone = PhoneNumber::tryParse($data->clientPhone);

        Validator::make(
            ['clientPhone' => $data->clientPhone, 'consent' => $data->consent, ...$basic->toArray()],
            [
                'clientPhone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($phone): void {
                    if ($phone === null) {
                        $fail(__('Enter a valid mobile number (an Indian number, or start with + for abroad).'));
                    }
                }],
                'consent' => ['accepted'],
                ...ProfileRules::basic($basic->toArray()),
            ],
            ['consent.accepted' => __('Please confirm the client has agreed before you save the profile.')],
            ['clientPhone' => __('client mobile'), ...ProfileRules::attributes()],
        )->validate();

        /** @var PhoneNumber $phone */
        return $phone;
    }

    /**
     * The claim key must be unused: by any account (deleted ones too — their number may come back
     * by restore) and by any other profile. The rows are locked so two bureaus can't take one number.
     *
     * @throws ValidationException
     */
    private function assertPhoneFree(PhoneNumber $phone): void
    {
        $taken = User::withTrashed()->where('phone', $phone->e164())->lockForUpdate()->exists()
            || Profile::withTrashed()->where('client_phone', $phone->e164())->lockForUpdate()->exists()
            || $this->bans->isBanned(BannedIdentifierType::Phone, $phone->e164());

        if ($taken) {
            throw ValidationException::withMessages(['clientPhone' => self::phoneTakenMessage()]);
        }
    }

    public static function phoneTakenMessage(): string
    {
        return __('This mobile number can’t be used for a new profile. It may already be registered on Oppam — a client with an account manages their own profile.');
    }

    private function insert(User $actor, BrokerStaff $staff, PhoneNumber $phone, BasicDetailsData $basic): Profile
    {
        $profile = new Profile;
        $profile->forceFill([
            'user_id' => null,
            'code' => $this->codes->next(),
            'first_name' => trim((string) $basic->first_name),
            'gender' => $basic->gender,
            'status' => ProfileStatus::Draft,
            'owner_type' => ProfileOwnerType::BrokerManaged,
            'managed_by_broker_id' => $staff->broker_id,
            'client_phone' => $phone->e164(),
            'broker_id' => $staff->broker_id,
            'broker_verified' => true,
            'broker_consent_confirmed_at' => now(),
            'consent_confirmed_by_user_id' => $actor->id,
            'created_by_user_id' => $actor->id,
            'assigned_staff_id' => $staff->isAssignedOnly() ? $staff->id : null,
        ])->save();

        $referral = new BrokerReferral;
        $referral->forceFill([
            'broker_id' => $staff->broker_id,
            'profile_id' => $profile->id,
            'source' => ReferralSource::Managed,
            'status' => ReferralStatus::Pending,
        ])->save();

        return $profile;
    }
}
