<?php

declare(strict_types=1);

namespace App\Queries\Admin\Comms;

use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds candidate member query based on A14 campaign segment filters.
 */
final class SegmentQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    public function build(array $filters, string $channel = 'in_app'): Builder
    {
        $query = User::query()
            ->where('role', UserRole::Member->value)
            ->where('status', UserStatus::Active->value)
            ->whereNull('deleted_at');

        if ($channel === 'email') {
            $query->whereNotNull('email')->whereNotNull('email_verified_at');
        } elseif ($channel === 'sms') {
            $query->whereNotNull('phone_verified_at');
        }

        $query->whereHas('profile', function (Builder $profileQuery) use ($filters): void {
            $profileQuery->where('status', ProfileStatus::Active);

            if (! empty($filters['gender'])) {
                $profileQuery->where('gender', $filters['gender']);
            }

            if (! empty($filters['religion_id'])) {
                $profileQuery->where('religion_id', (int) $filters['religion_id']);
            }

            if (! empty($filters['district_id'])) {
                $profileQuery->where('district_id', (int) $filters['district_id']);
            }

            if (isset($filters['is_verified'])) {
                $profileQuery->where('is_verified', (bool) $filters['is_verified']);
            }

            if (isset($filters['is_premium'])) {
                $profileQuery->where('is_premium', (bool) $filters['is_premium']);
            }

            if (! empty($filters['completeness_min'])) {
                $profileQuery->where('completeness', '>=', (int) $filters['completeness_min']);
            }

            if (! empty($filters['active_within_days'])) {
                $profileQuery->where('last_active_at', '>=', now()->subDays((int) $filters['active_within_days']));
            }

            if (isset($filters['has_photo'])) {
                if ($filters['has_photo']) {
                    $profileQuery->whereHas('media');
                } else {
                    $profileQuery->whereDoesntHave('media');
                }
            }

            if (isset($filters['is_broker'])) {
                if ($filters['is_broker']) {
                    $profileQuery->whereNotNull('broker_id');
                } else {
                    $profileQuery->whereNull('broker_id');
                }
            }
        });

        if (! empty($filters['registered_from'])) {
            $query->where('created_at', '>=', $filters['registered_from']);
        }

        if (! empty($filters['registered_to'])) {
            $query->where('created_at', '<=', $filters['registered_to']);
        }

        // Respect marketing / announcement opt-out if opted out
        $query->whereDoesntHave('notificationPreferences', function (Builder $prefQuery) use ($channel): void {
            $prefQuery->where('event', 'admin_announcement')
                ->where(function ($q) use ($channel): void {
                    if ($channel === 'email') {
                        $q->where('email', false);
                    } elseif ($channel === 'sms') {
                        $q->where('sms', false);
                    } elseif ($channel === 'in_app') {
                        $q->where('in_app', false);
                    }
                });
        });

        return $query;
    }
}
