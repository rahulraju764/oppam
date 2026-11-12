<?php

declare(strict_types=1);

namespace App\Domain\Reports\Calculators;

use App\Data\Reports\SnapshotRow;
use App\Domain\Reports\DayWindow;
use App\Enums\ReportMetric;
use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * A09 growth numbers for one IST day (P9.1a): signups — in total and by source, gender and district
 * (the profile's district as known when the snapshot runs; "none" until the wizard sets it) —
 * and profiles approved for the first time. Member accounts only: a bureau's client profile has
 * no account until it is claimed. One grouped query per family.
 */
final class GrowthCalculator
{
    /** @return list<SnapshotRow> */
    public function day(DayWindow $day): array
    {
        $signups = DB::table('users')
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->where('users.role', UserRole::Member->value)
            ->where('users.created_at', '>=', $day->from)->where('users.created_at', '<', $day->to)
            ->selectRaw('users.signup_source as source, profiles.gender as gender, profiles.district_id as district, COUNT(*) as n')
            ->groupBy('users.signup_source', 'profiles.gender', 'profiles.district_id')
            ->get();

        $rows = [new SnapshotRow(ReportMetric::Signups, (int) $signups->sum('n'))];
        foreach (['source' => 'source', 'gender' => 'gender', 'district' => 'district'] as $dim => $column) {
            foreach ($signups->groupBy(fn (object $r): string => (string) ($r->{$column} ?? 'none')) as $value => $group) {
                $rows[] = new SnapshotRow(ReportMetric::Signups, (int) $group->sum('n'), $dim.':'.$value);
            }
        }

        $rows[] = new SnapshotRow(ReportMetric::ProfilesApproved, DB::table('profiles')
            ->where('published_at', '>=', $day->from)->where('published_at', '<', $day->to)->count());

        return $rows;
    }
}
