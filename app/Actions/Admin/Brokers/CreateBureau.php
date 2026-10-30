<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers;

use App\Actions\Admin\Brokers\Concerns\ManagesBureaus;
use App\Data\Broker\NewBureauData;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Broker\BrokerCodeGenerator;
use App\Support\Facades\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A07 "create broker" (§11A B.12; decisions 2026-10-29 — the Owner applies normally): an admin
 * opens a bureau record — code from the sequence, never typed (R-A07-1), APPLIED, no KYC, no Owner
 * login. The Owner then applies at /broker/apply?bureau=<code> with this mobile; ApplyAsBroker
 * links their new login to this bureau instead of opening another, and KYC follows exactly as for
 * a self-applicant (R-A07-2 — an admin can't skip it). A mobile that is already an Oppam login or
 * another open bureau's number is refused. Needs `brokers.edit`; audited.
 */
final class CreateBureau
{
    use ManagesBureaus;

    public function __construct(
        private readonly BrokerCodeGenerator $codes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws BrokerStateConflict the mobile is taken
     */
    public function handle(AdminUser $admin, NewBureauData $data): Broker
    {
        $this->authorizeFor($admin, 'brokers.edit');

        return DB::transaction(function () use ($admin, $data): Broker {
            $phone = $data->phone->e164();
            $taken = User::withTrashed()->where('phone', $phone)->lockForUpdate()->exists()
                || Broker::query()->where('phone', $phone)
                    ->whereNotIn('status', [BrokerStatus::Rejected, BrokerStatus::Deactivated])->lockForUpdate()->exists();

            if ($taken) {
                throw BrokerStateConflict::phoneTaken();
            }

            $broker = new Broker;
            $broker->forceFill([
                'code' => $this->codes->next(),
                'name' => $data->name,
                'bureau_name' => $data->bureauName,
                'phone' => $phone,
                'email' => $data->email,
                'district_id' => $data->districtId,
                'status' => BrokerStatus::Applied,
                'kyc_status' => BrokerKycStatus::NotSubmitted,
                'staff_seat_limit' => Settings::int(SettingKey::BrokerStaffSeatsDefault),
                'created_by_admin_id' => $admin->id,
            ])->save();

            $this->audit->record('brokers.created', $broker, after: ['status' => BrokerStatus::Applied->value, 'phone' => $phone],   // AuditLogger masks it
                actor: $admin, subjectLabel: $broker->code);

            return $broker;
        });
    }
}
