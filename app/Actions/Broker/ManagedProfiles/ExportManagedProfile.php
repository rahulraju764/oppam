<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Data\Broker\ExportSnapshot;
use App\Domain\Broker\BiodataBuilder;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BrokerExportType;
use App\Enums\BureauPermission;
use App\Models\Broker;
use App\Models\BrokerProfileExport;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A bureau export of a managed client — biodata PDF or photo package (§11A B.7) — the highest-risk
 * bureau surface (B.18). One transaction with the profile's row locked:
 *  1. ownership is decided on that locked row, inside the query (ManagedProfileScope: the caller's
 *     own bureau, BROKER_MANAGED, unclaimed, not deleted, assigned-only staff on their own clients)
 *     — never a post-filter; no export exists for referred members or search results, and there is
 *     no admin override (R-M13-12);
 *  2. BureauGate `profiles.export`: role ceiling minus switch-offs, ACTIVE and KYC-verified bureau
 *     (R-M13-19 / R-M13-21 / R-A07-8);
 *  3. a per-login hourly cap (`oppam.broker.exports_per_hour`);
 *  4. a `broker_profile_exports` row with the bureau, the individual staff user and the IP — on
 *     EVERY attempt, allowed or not (R-M13-13 / R-M13-23), append-only (R-A07-7);
 *  5. when allowed, the biodata content (and, for the photo package, WHICH approved originals) is
 *     taken while the lock is still held; the zip reads those files' bytes just after the commit.
 * ClaimManagedProfile takes the same row lock, so an export can never run on a profile whose claim
 * has committed (B.19 "export racing a claim").
 *
 * A refusal is a 403 with `X-Error-Code` (PROFILE_NOT_MANAGED_BY_BROKER, BROKER_KYC_REQUIRED, …)
 * plus a security log line (ids and codes only); an unknown code is a 404 and writes nothing (the
 * row needs a profile). The controller streams the file — Livewire only links to it.
 */
final class ExportManagedProfile
{
    public const NOT_MANAGED = 'PROFILE_NOT_MANAGED_BY_BROKER';

    public const TOO_MANY = 'BROKER_EXPORT_RATE';

    public const NO_PHOTOS = 'BROKER_EXPORT_NO_PHOTOS';

    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly BiodataBuilder $builder,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws NotFoundHttpException no profile with this code
     * @throws HttpException 403 refused (logged), 429 too many exports (logged)
     */
    public function handle(User $login, string $code, BrokerExportType $type, ?string $ip): ExportSnapshot
    {
        $staff = $this->gate->staff($login);
        $broker = $staff?->broker;
        if ($staff === null || ! $broker instanceof Broker) {
            throw new HttpException(403, __('This account is not part of a bureau.'), null, ['X-Error-Code' => BureauGate::NOT_STAFF]);
        }

        $profileId = Profile::withTrashed()->where('code', $code)->value('id') ?? throw new NotFoundHttpException;
        $key = 'broker-exports:'.$login->id;

        /** @var array{0: ExportSnapshot|null, 1: int, 2: string, 3: string} $outcome */
        $outcome = DB::transaction(function () use ($login, $staff, $broker, $profileId, $type, $ip, $key): array {
            Profile::withTrashed()->whereKey($profileId)->lockForUpdate()->first();
            $managed = $this->scope->query($staff)->whereKey($profileId)->first();

            [$status, $reason, $message] = $this->refusal($login, $managed, $type, $key);

            $row = new BrokerProfileExport;
            $row->forceFill([
                'broker_id' => $broker->id, 'user_id' => $login->id, 'profile_id' => $profileId,
                'export_type' => $type, 'allowed' => $status === 0, 'ip' => $ip,
            ])->save();

            if ($status !== 0 || $managed === null) {
                return [null, $status, $reason, $message];
            }

            $this->limiter->hit($key, 3600);

            $snapshot = $type === BrokerExportType::BiodataPdf
                ? new ExportSnapshot($type, $managed->code, biodata: $this->builder->biodata($managed, $broker))
                : new ExportSnapshot($type, $managed->code, photos: $this->builder->photoFiles($managed));

            $this->audit->record('broker.profile_exported', $managed, after: [
                'broker' => $broker->code, 'type' => $type->value,
            ], actor: $login, subjectLabel: $managed->code);

            return [$snapshot, 0, '', ''];
        });

        [$snapshot, $status, $reason, $message] = $outcome;

        if ($snapshot === null) {
            Log::warning('Bureau export refused', [
                'security' => true, 'reason' => $reason, 'user_id' => $login->id, 'broker' => $broker->code,
                'profile' => $code, 'type' => $type->value,
            ]);

            throw new HttpException($status, $message, null, ['X-Error-Code' => $reason]);
        }

        return $snapshot;
    }

    /**
     * Why this attempt is refused — [0, '', ''] when it isn't.
     *
     * @return array{0: int, 1: string, 2: string} [HTTP status, X-Error-Code, message]
     */
    private function refusal(User $login, ?Profile $managed, BrokerExportType $type, string $key): array
    {
        if ($managed === null) {
            return [403, self::NOT_MANAGED, __('Your bureau can export only the profiles it manages.')];
        }

        $gate = $this->gate->inspect($login, BureauPermission::ProfilesExport);
        if ($gate->denied()) {
            return [403, (string) $gate->code(), (string) $gate->message()];
        }

        if ($this->limiter->tooManyAttempts($key, (int) config('oppam.broker.exports_per_hour'))) {
            return [429, self::TOO_MANY, __('Too many exports in the last hour. Please try again later.')];
        }

        if ($type === BrokerExportType::PhotoPackage && $this->builder->photoFiles($managed) === []) {
            return [409, self::NO_PHOTOS, __('This profile has no approved photos to download yet.')];
        }

        return [0, '', ''];
    }
}
