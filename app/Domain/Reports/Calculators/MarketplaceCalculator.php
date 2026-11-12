<?php

declare(strict_types=1);

namespace App\Domain\Reports\Calculators;

use App\Data\Reports\SnapshotRow;
use App\Domain\Reports\DayWindow;
use App\Enums\PhotoStatus;
use App\Enums\ProfileStatus;
use App\Enums\ReportMetric;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * A09 marketplace health, as a level read on the day it runs (P9.1a): live (ACTIVE) profiles by
 * gender, by district × gender and by age band × gender, and those with an approved photo. A
 * past day keeps the level it had when it was read — levels can't be rebuilt afterwards.
 */
final class MarketplaceCalculator
{
    public const AGE_BANDS = ['18-24' => [18, 24], '25-29' => [25, 29], '30-34' => [30, 34], '35-39' => [35, 39], '40+' => [40, 200]];

    /** @return list<SnapshotRow> */
    public function day(DayWindow $day): array
    {
        $live = DB::table('profiles')->where('status', ProfileStatus::Active->value)->whereNull('deleted_at');

        $groups = (clone $live)
            ->selectRaw('gender, district_id, TIMESTAMPDIFF(YEAR, dob, ?) as age, COUNT(*) as n', [$day->date])
            ->groupBy('gender', 'district_id', 'age')
            ->get();

        $rows = [new SnapshotRow(ReportMetric::ActiveProfiles, (int) $groups->sum('n'))];
        foreach ($groups->groupBy('gender') as $gender => $byGender) {
            $rows[] = new SnapshotRow(ReportMetric::ActiveProfiles, (int) $byGender->sum('n'), 'gender:'.$gender);
            foreach ($byGender->groupBy(fn (object $r): string => (string) ($r->district_id ?? 'none')) as $district => $group) {
                $rows[] = new SnapshotRow(ReportMetric::ActiveProfiles, (int) $group->sum('n'), 'district:'.$district.'|gender:'.$gender);
            }
            foreach ($byGender->groupBy(fn (object $r): string => self::band($r->age === null ? null : (int) $r->age)) as $band => $group) {
                $rows[] = new SnapshotRow(ReportMetric::ActiveProfiles, (int) $group->sum('n'), 'age:'.$band.'|gender:'.$gender);
            }
        }

        $rows[] = new SnapshotRow(ReportMetric::ActiveProfilesWithPhoto, (clone $live)->whereExists(fn ($q) => $q->selectRaw('1')->from('media')
            ->where('media.model_type', (new Profile)->getMorphClass())->whereColumn('media.model_id', 'profiles.id')
            ->where('media.collection_name', Profile::PHOTOS)->where('media.moderation_status', PhotoStatus::Approved->value))->count());

        return $rows;
    }

    public static function band(?int $age): string
    {
        foreach (self::AGE_BANDS as $band => [$min, $max]) {
            if ($age !== null && $age >= $min && $age <= $max) {
                return $band;
            }
        }

        return 'unknown';
    }
}
