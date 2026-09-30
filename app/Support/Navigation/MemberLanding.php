<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Routing\Router;

/**
 * Where a member goes after signing in (M01): the wizard while the profile is a DRAFT (R-M02-2),
 * otherwise the dashboard — or the home page until the dashboard is built (P2.3). Brokers land
 * on the home page until the broker portal exists (P7.1).
 */
final class MemberLanding
{
    public function __construct(private readonly Router $router) {}

    public function url(mixed $user): string
    {
        if (! $user instanceof User || $user->role !== UserRole::Member) {
            return route('home');
        }

        $status = $user->profile?->status;

        if ($status === null || $status === ProfileStatus::Draft) {
            return route('member.onboarding', ['step' => 1]);
        }

        return $this->router->has('member.dashboard') ? route('member.dashboard') : route('home');
    }
}
