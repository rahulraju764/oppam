<?php

declare(strict_types=1);

namespace App\Data\Broker;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Models\User;
use Illuminate\Routing\Router;

/**
 * What the broker layout shows about the signed-in bureau login (§11A B.11 "bureau name + role
 * chip", "menu items hidden per permission"): codes and labels only. A menu item appears when its
 * route exists and BureauGate allows it; items that wait for KYC are shown locked with the reason.
 * Read fresh on every request (BureauGate).
 */
final readonly class BrokerChromeData
{
    /** route name => [label, icon, permission] — in sidebar order; later sessions add their routes. */
    public const MENU = [
        'broker.dashboard' => ['Dashboard', 'fa-tachometer', BureauPermission::DashboardView],
        'broker.profiles.index' => ['Client profiles', 'fa-users', BureauPermission::ProfilesView],
        'broker.profiles.create' => ['Add a profile', 'fa-user-plus', BureauPermission::ProfilesCreate],
        'broker.import' => ['Bulk import', 'fa-upload', BureauPermission::ProfilesImport],
        'broker.referrals' => ['Referrals', 'fa-share-alt', BureauPermission::ReferralsView],
        'broker.earnings' => ['Earnings', 'fa-inr', BureauPermission::EarningsView],
        'broker.staff.index' => ['Staff', 'fa-id-badge', BureauPermission::StaffManage],
        'broker.activity' => ['Activity', 'fa-history', BureauPermission::ActivityView],
        'broker.materials' => ['Materials', 'fa-qrcode', BureauPermission::MaterialsView],
        'broker.kyc' => ['KYC & bank', 'fa-id-card-o', BureauPermission::KycManage],
    ];

    /**
     * @param  list<array{route: string, label: string, icon: string, locked: bool}>  $menu
     */
    public function __construct(
        public string $bureauName,
        public string $code,
        public string $roleLabel,
        public string $statusLabel,
        public bool $verified,
        public array $menu,
    ) {}

    public static function current(): ?self
    {
        $user = auth('web')->user();
        $gate = app(BureauGate::class);
        $staff = $user instanceof User ? $gate->staff($user) : null;
        $broker = $staff?->broker;

        if ($staff === null || $broker === null) {
            return null;
        }

        $permissions = $gate->permissions($user);
        $router = app(Router::class);
        $menu = [];

        foreach (self::MENU as $route => [$label, $icon, $permission]) {
            $allowed = in_array($permission, $permissions['allowed'], true);
            $locked = in_array($permission, $permissions['locked'], true);

            if ($router->has($route) && ($allowed || $locked)) {
                $menu[] = ['route' => $route, 'label' => __($label), 'icon' => $icon, 'locked' => ! $allowed];
            }
        }

        return new self(
            bureauName: $broker->displayName(),
            code: $broker->code,
            roleLabel: $staff->role->label(),
            statusLabel: $broker->status->label(),
            verified: $broker->isVerifiedAndActive(),
            menu: $menu,
        );
    }
}
