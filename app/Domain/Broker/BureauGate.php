<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauPermission;
use App\Enums\BureauStaffStatus;
use App\Enums\UserRole;
use App\Models\BrokerStaff;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Who in a bureau may do what (PRD §11A B.2, R-M13-18/19/21, §8.1). A bureau login's permissions
 * are its role's CEILING minus the Owner's switch-offs; handling other people's data (create,
 * import, export) also needs an ACTIVE, KYC-verified bureau. Read from the database on every
 * request — memoised only on the current Request object, so a suspension, a KYC revocation or a
 * switch-off applies to the very next request (B.18 "no caching past a change").
 *
 * An applicant Owner is INVITED until KYC approval (decisions 2026-10-23): they reach only the
 * pre-KYC pages (dashboard, KYC, materials — B.3 "Before KYC"). A bureau whose create / import
 * rights A07 paused (R-A07-10, upheld consent disputes) is refused both, and nothing else. Denials carry a machine code
 * (BROKER_KYC_REQUIRED, BROKER_INACTIVE, …) that the 403 response repeats in `X-Error-Code`.
 */
final class BureauGate
{
    public const KYC_REQUIRED = 'BROKER_KYC_REQUIRED';

    public const INACTIVE = 'BROKER_INACTIVE';

    public const NOT_STAFF = 'BROKER_NOT_STAFF';

    public const STAFF_INACTIVE = 'BROKER_STAFF_INACTIVE';

    public const NOT_PERMITTED = 'BUREAU_PERMISSION';

    public const CREATION_PAUSED = 'BROKER_CREATION_PAUSED';

    private const MEMO = 'oppam.bureau-staff';

    /** Bureau statuses whose logins may use the portal at all. */
    private const PORTAL_STATUSES = [BrokerStatus::Applied, BrokerStatus::KycPending, BrokerStatus::Active];

    /** What an applicant Owner (INVITED, before KYC approval) may open. */
    private const PRE_KYC = [BureauPermission::DashboardView, BureauPermission::KycManage, BureauPermission::MaterialsView];

    public function __construct(private readonly Request $request) {}

    /** The caller's bureau login with its bureau, fresh from the database (once per request). */
    public function staff(?User $user): ?BrokerStaff
    {
        if ($user === null || $user->role !== UserRole::Broker) {
            return null;
        }

        $memo = $this->request->attributes->get(self::MEMO);
        if (is_array($memo) && ($memo['user'] ?? null) === $user->id) {
            return $memo['staff'];
        }

        $staff = BrokerStaff::query()->where('user_id', $user->id)->with('broker')->first();
        $this->request->attributes->set(self::MEMO, ['user' => $user->id, 'staff' => $staff]);

        return $staff;
    }

    /** Forget the memo (after a change in the same request, e.g. KYC approval in a test). */
    public function forget(): void
    {
        $this->request->attributes->remove(self::MEMO);
    }

    /** May this login use the portal at all (broker.active + broker.staff.active)? */
    public function portal(?User $user): Response
    {
        $staff = $this->staff($user);
        $broker = $staff?->broker;

        if ($staff === null || $broker === null) {
            return Response::deny(__('This account is not part of a bureau.'), self::NOT_STAFF);
        }

        if (! in_array($broker->status, self::PORTAL_STATUSES, true)) {
            return Response::deny(match ($broker->status) {
                BrokerStatus::Rejected => __('Your bureau application was not approved.'),
                BrokerStatus::Suspended => __('Your bureau is suspended. Please contact Oppam support.'),
                default => __('Your bureau account is closed.'),
            }, self::INACTIVE);
        }

        $applicant = $staff->status === BureauStaffStatus::Invited && $staff->isOwner() && $broker->status !== BrokerStatus::Active;
        if ($staff->status !== BureauStaffStatus::Active && ! $applicant) {
            return Response::deny(__('Your bureau login is not active. Please ask the bureau owner.'), self::STAFF_INACTIVE);
        }

        return Response::allow();
    }

    public function inspect(?User $user, BureauPermission $permission): Response
    {
        $portal = $this->portal($user);
        if ($portal->denied()) {
            return $portal;
        }

        /** @var BrokerStaff $staff */
        $staff = $this->staff($user);
        /** @var \App\Models\Broker $broker */
        $broker = $staff->broker;

        // An applicant Owner: the pre-KYC pages, and the data-handling ones answer "KYC first" below.
        $applicant = $staff->status === BureauStaffStatus::Invited;
        if (! in_array($permission, BureauPermission::ceiling($staff->role), true)
            || ($applicant && ! in_array($permission, self::PRE_KYC, true) && ! $permission->needsVerifiedBureau())
            || $this->switchedOff($staff, $permission)) {
            return Response::deny(__('Your role in this bureau doesn’t include this.'), self::NOT_PERMITTED);
        }

        if ($permission->needsVerifiedBureau() && ! $broker->isVerifiedAndActive()) {
            return $broker->kyc_status !== BrokerKycStatus::Verified
                ? Response::deny(__('Complete your bureau’s KYC (PAN and bank details) before creating, importing or exporting profiles.'), self::KYC_REQUIRED)
                : Response::deny(__('Your bureau is not active.'), self::INACTIVE);
        }

        if (in_array($permission, [BureauPermission::ProfilesCreate, BureauPermission::ProfilesImport], true) && $broker->creation_paused_at !== null) {
            return Response::deny(__('Adding and importing profiles is paused for your bureau while our team reviews client complaints. Please contact Oppam support.'), self::CREATION_PAUSED);
        }

        return Response::allow();
    }

    /**
     * The role part only: an active login of a portal-status bureau whose role (minus the Owner's
     * switch-offs) includes the permission — WITHOUT the "ACTIVE + KYC-verified bureau" requirement.
     * For what B.9 still allows once KYC is revoked: seeing a batch's summary and cancelling it.
     */
    public function inspectRole(?User $user, BureauPermission $permission): Response
    {
        $response = $this->inspect($user, $permission);

        return in_array($response->code(), [self::KYC_REQUIRED, self::INACTIVE, self::CREATION_PAUSED], true) && $this->portal($user)->allowed()
            && $this->staff($user)?->status === BureauStaffStatus::Active
            ? Response::allow()
            : $response;
    }

    /**
     * inspectRole() for Actions and middleware: the caller's login, else a 403 with the denial code.
     *
     * @throws HttpException
     */
    public function authorizeRole(?User $user, BureauPermission $permission): BrokerStaff
    {
        $response = $this->inspectRole($user, $permission);

        if ($response->denied()) {
            throw new HttpException(403, (string) $response->message(), null, ['X-Error-Code' => (string) $response->code()]);
        }

        /** @var BrokerStaff */
        return $this->staff($user);
    }

    public function allows(?User $user, BureauPermission $permission): bool
    {
        return $this->inspect($user, $permission)->allowed();
    }

    /**
     * For Actions and components: the caller's bureau login when allowed, else a 403 carrying the
     * denial code (X-Error-Code). Nothing is written before this passes.
     *
     * @throws HttpException
     */
    public function authorize(?User $user, BureauPermission $permission): BrokerStaff
    {
        $response = $this->inspect($user, $permission);

        if ($response->denied()) {
            throw new HttpException(403, (string) $response->message(), null, ['X-Error-Code' => (string) $response->code()]);
        }

        /** @var BrokerStaff */
        return $this->staff($user);
    }

    /**
     * Every permission this login holds now, and those it would hold once the bureau is verified
     * (the sidebar shows those locked, with the reason).
     *
     * @return array{allowed: list<BureauPermission>, locked: list<BureauPermission>}
     */
    public function permissions(?User $user): array
    {
        $allowed = [];
        $locked = [];

        foreach (BureauPermission::cases() as $permission) {
            $response = $this->inspect($user, $permission);

            if ($response->allowed()) {
                $allowed[] = $permission;
            } elseif (in_array($response->code(), [self::KYC_REQUIRED, self::INACTIVE], true) && $this->portal($user)->allowed()) {
                $locked[] = $permission;
            }
        }

        return ['allowed' => $allowed, 'locked' => $locked];
    }

    /** The Owner's switch-offs apply to staff only — an Owner can't lock themself out (R-M13-19, R-M13-24). */
    private function switchedOff(BrokerStaff $staff, BureauPermission $permission): bool
    {
        return ! $staff->isOwner() && in_array($permission->value, $staff->disabled_permissions ?? [], true);
    }
}
