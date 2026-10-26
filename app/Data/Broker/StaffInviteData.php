<?php

declare(strict_types=1);

namespace App\Data\Broker;

/** The Owner's "invite staff" form (§11A B.8), as typed — InviteStaff validates and normalises it. */
final readonly class StaffInviteData
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $role,
        public string $scope,
    ) {}

    /** @return array{name: string, phone: string, role: string, scope: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'phone' => $this->phone, 'role' => $this->role, 'scope' => $this->scope];
    }
}
