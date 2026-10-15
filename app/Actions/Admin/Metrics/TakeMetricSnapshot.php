<?php

declare(strict_types=1);

namespace App\Actions\Admin\Metrics;

use App\Enums\Gender;
use App\Enums\InterestStatus;
use App\Enums\MessageType;
use App\Enums\Metric;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\InterestEvent;
use App\Models\Like;
use App\Models\Message;
use App\Models\MetricSnapshot;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Compute every dashboard KPI for one IST day and store it in `metric_snapshots` (PRD A02: the
 * dashboard reads snapshots, never live aggregates). Run hourly for today (the row is rewritten,
 * so the day's figures grow through the day) and once just after midnight for yesterday, which
 * closes that day. Idempotent: one row per metric and day (upsert on the unique key).
 *
 * "Flow" metrics count what happened inside the IST day. "Stock" metrics are levels as of now,
 * so they are only written for today — a past day keeps the level it had when it was last run.
 */
final class TakeMetricSnapshot
{
    /** @return array<string, int> metric value => stored number */
    public function handle(?CarbonImmutable $day = null): array
    {
        $timezone = (string) config('oppam.display_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $day = ($day ?? $today)->setTimezone($timezone)->startOfDay();
        $from = $day->utc();
        $to = $day->addDay()->utc();
        $isToday = $day->equalTo($today);
        $now = CarbonImmutable::now();

        $values = [];
        foreach (Metric::cases() as $metric) {
            if ($metric->isStock() && ! $isToday) {
                continue;
            }

            $values[$metric->value] = $this->compute($metric, $from, $to);
        }

        MetricSnapshot::query()->upsert(
            array_map(fn (string $metric, int $value): array => [
                'id' => (string) Str::ulid(),
                'metric' => $metric,
                'day' => $day->toDateString(),
                'value' => $value,
                'computed_at' => $now,
            ], array_keys($values), $values),
            ['metric', 'day'],
            ['value', 'computed_at'],
        );

        return $values;
    }

    private function compute(Metric $metric, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return match ($metric) {
            Metric::TotalMembers => $this->members()->count(),
            Metric::NewMembers => $this->members()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            Metric::ActiveMembers7d => $this->members()->where('last_seen_at', '>=', now()->subDays(7))->count(),
            Metric::PaidMembers => (int) Subscription::query()->currentAt(Carbon::now())
                ->whereHas('plan', fn ($q) => $q->where('code', '!=', PlanCode::Free->value))
                ->distinct()->count('profile_id'),
            Metric::Activations => Profile::query()->where('published_at', '>=', $from)->where('published_at', '<', $to)->count(),
            Metric::ActiveMale => Profile::query()->where('status', ProfileStatus::Active->value)->where('gender', Gender::Male->value)->count(),
            Metric::ActiveFemale => Profile::query()->where('status', ProfileStatus::Active->value)->where('gender', Gender::Female->value)->count(),
            Metric::Likes => Like::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            Metric::InterestsSent => $this->interestEvents(InterestStatus::Pending, $from, $to),
            Metric::InterestsAccepted => $this->interestEvents(InterestStatus::Accepted, $from, $to),
            Metric::Messages => Message::query()->where('type', '!=', MessageType::System->value)
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
        };
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function members(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->where('role', UserRole::Member->value)->where('status', '!=', UserStatus::Deleted->value);
    }

    private function interestEvents(InterestStatus $status, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return InterestEvent::query()->where('status', $status->value)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
    }
}
