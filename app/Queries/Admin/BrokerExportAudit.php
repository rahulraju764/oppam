<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Enums\BrokerExportType;
use App\Enums\SettingKey;
use App\Models\Broker;
use App\Models\BrokerProfileExport;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Queries\Broker\BureauActivity;
use App\Support\Facades\Settings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The A07 export audit (§11A B.12, R-A07-7, R-M13-13/23): every `broker_profile_exports` row —
 * allowed and refused — newest first, read-only, with the bureau code, the staff login's code, the
 * profile code and the type; a deactivated bureau's history is returned in full (B.19).
 *
 * Filters (B.17 "must resolve to existing rows"): a bureau code, staff code or profile code that
 * matches nothing is reported back as unknown instead of silently listing nothing.
 *
 * Anomaly highlighting (B.12 "unusual export volume/pattern … same 40 PDFs twice in an hour"), per
 * row, from two correlated counts on the existing indexes:
 *  - `burst`: exports by the same login in the hour up to this one > `broker.export_anomaly_per_hour`;
 *  - `repeat`: the same profile exported by the same bureau earlier within
 *    `broker.export_repeat_window_minutes`.
 */
final class BrokerExportAudit
{
    public const PER_PAGE = 30;

    /**
     * @param  array{broker?: string, staff?: string, profile?: string, type?: string, allowed?: string, from?: string, to?: string}  $filters
     * @return array{rows: LengthAwarePaginator<int, BrokerProfileExport>, unknown: list<string>}
     */
    public function page(array $filters): array
    {
        $unknown = [];
        $query = BrokerProfileExport::query()
            ->from('broker_profile_exports as e')
            ->join('brokers as b', 'b.id', '=', 'e.broker_id')
            ->leftJoin('broker_staff as s', fn ($j) => $j->on('s.user_id', '=', 'e.user_id')->on('s.broker_id', '=', 'e.broker_id'))
            ->leftJoin('profiles as p', 'p.id', '=', 'e.profile_id')
            ->select(['e.id', 'e.broker_id', 'e.user_id', 'e.profile_id', 'e.export_type', 'e.allowed', 'e.created_at',
                'b.code as broker_code', 's.code as staff_code', 's.role as staff_role', 'p.code as profile_code'])
            ->selectSub($this->burst(), 'login_hour_count')
            ->selectSub($this->repeat(), 'repeat_count');

        foreach (['broker' => [Broker::class, 'e.broker_id'], 'profile' => [Profile::class, 'e.profile_id']] as $key => [$model, $column]) {
            $code = strtoupper(trim((string) ($filters[$key] ?? '')));
            if ($code === '') {
                continue;
            }
            $id = $model === Profile::class ? Profile::withTrashed()->where('code', $code)->value('id') : $model::query()->where('code', $code)->value('id');
            $id === null ? $unknown[] = $key : $query->where($column, $id);
        }

        $staff = strtoupper(trim((string) ($filters['staff'] ?? '')));
        if ($staff !== '') {
            $userId = BrokerStaff::query()->where('code', $staff)->value('user_id');
            $userId === null ? $unknown[] = 'staff' : $query->where('e.user_id', $userId);
        }

        $type = BrokerExportType::tryFrom((string) ($filters['type'] ?? ''));
        $query->when($type !== null, fn (Builder $q) => $q->where('e.export_type', $type?->value))
            ->when(in_array($filters['allowed'] ?? '', ['yes', 'no'], true), fn (Builder $q) => $q->where('e.allowed', ($filters['allowed'] ?? '') === 'yes'));

        $tz = (string) config('oppam.display_timezone');
        $from = BureauActivity::date($filters['from'] ?? null, $tz)?->startOfDay()->utc();
        $to = BureauActivity::date($filters['to'] ?? null, $tz)?->endOfDay()->utc();
        $query->when($from !== null, fn (Builder $q) => $q->where('e.created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('e.created_at', '<=', $to));

        // An unknown code lists nothing (never "everything" by mistake).
        $query->when($unknown !== [], fn (Builder $q) => $q->whereRaw('1 = 0'));

        return [
            'rows' => $query->orderByDesc('e.created_at')->orderByDesc('e.id')->paginate(self::PER_PAGE),
            'unknown' => $unknown,
        ];
    }

    /** Is this row unusual (B.12)? Reads the two counts the page selected. */
    public static function anomaly(BrokerProfileExport $row): ?string
    {
        if ((int) $row->getAttribute('login_hour_count') > Settings::int(SettingKey::BrokerExportAnomalyPerHour)) {
            return 'burst';
        }

        return (int) $row->getAttribute('repeat_count') > 0 ? 'repeat' : null;
    }

    /** Exports by the same login in the hour up to (and including) this one. */
    private function burst(): QueryBuilder
    {
        return DB::table('broker_profile_exports as x')->selectRaw('count(*)')
            ->whereColumn('x.broker_id', 'e.broker_id')
            ->whereColumn('x.user_id', 'e.user_id')
            ->whereColumn('x.created_at', '<=', 'e.created_at')
            ->whereRaw('x.created_at > e.created_at - interval 1 hour');
    }

    /** Earlier exports of the same profile by the same bureau within the repeat window. */
    private function repeat(): QueryBuilder
    {
        $minutes = Settings::int(SettingKey::BrokerExportRepeatWindowMinutes);

        return DB::table('broker_profile_exports as y')->selectRaw('count(*)')
            ->whereColumn('y.profile_id', 'e.profile_id')
            ->whereColumn('y.broker_id', 'e.broker_id')
            ->whereColumn('y.created_at', '<', 'e.created_at')
            ->whereRaw('y.created_at >= e.created_at - interval ? minute', [$minutes]);
    }
}
