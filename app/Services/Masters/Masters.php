<?php

declare(strict_types=1);

namespace App\Services\Masters;

use App\Data\Masters\MasterItem;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MasterRecord;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Rasi;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use App\Models\Masters\State;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cached, active, ordered master-data lists for forms and filters (CLAUDE.md "Master data").
 * Never hardcode these lists in views. Cache keys include a version number that every master
 * write bumps (MasterDataObserver) — version keys rather than cache tags, because the local
 * `database` cache store has no tags (docs/decisions.md). Caste lists are always per religion.
 */
final class Masters
{
    public const VERSION_KEY = 'masters:version';

    private const TTL_SECONDS = 86_400;

    public function __construct(private readonly Cache $cache) {}

    /** @return list<MasterItem> */
    public function religions(): array
    {
        return $this->remember('religions', fn () => Religion::query());
    }

    /** @return list<MasterItem> */
    public function castesForReligion(int $religionId): array
    {
        return $this->remember("castes:{$religionId}", fn () => Caste::query()->forReligion($religionId));
    }

    /** @return list<MasterItem> */
    public function stars(): array
    {
        return $this->remember('stars', fn () => Star::query());
    }

    /** @return list<MasterItem> */
    public function rasis(): array
    {
        return $this->remember('rasis', fn () => Rasi::query());
    }

    /** @return list<MasterItem> */
    public function countries(): array
    {
        return $this->remember('countries', fn () => Country::query());
    }

    /** @return list<MasterItem> */
    public function statesForCountry(int $countryId): array
    {
        return $this->remember("states:{$countryId}", fn () => State::query()->forCountry($countryId));
    }

    /** @return list<MasterItem> */
    public function districtsForState(int $stateId): array
    {
        return $this->remember("districts:{$stateId}", fn () => District::query()->forState($stateId));
    }

    /** @return list<MasterItem> every active district of every active state, state by state */
    public function allDistricts(): array
    {
        $districts = [];
        foreach ($this->countries() as $country) {
            foreach ($this->statesForCountry($country->id) as $state) {
                array_push($districts, ...$this->districtsForState($state->id));
            }
        }

        return $districts;
    }

    /** @return list<MasterItem> */
    public function education(): array
    {
        return $this->remember('education', fn () => Education::query());
    }

    /** @return list<MasterItem> */
    public function occupations(): array
    {
        return $this->remember('occupations', fn () => Occupation::query());
    }

    /** @return list<MasterItem> */
    public function incomeBands(): array
    {
        return $this->remember('income-bands', fn () => IncomeBand::query());
    }

    /** @return list<MasterItem> */
    public function motherTongues(): array
    {
        return $this->remember('mother-tongues', fn () => MotherTongue::query());
    }

    /** @return list<MasterItem> diet, smoking, drinking, complexion, body_type, family_type/status/values */
    public function options(string $group): array
    {
        return $this->remember("options:{$group}", fn () => MasterOption::query()->inGroup($group));
    }

    /**
     * id => label, ready for <x-ui.select :options>.
     *
     * @param  list<MasterItem>  $items
     * @return array<int, string>
     */
    public static function forSelect(array $items): array
    {
        $options = [];

        foreach ($items as $item) {
            $options[$item->id] = $item->label;
        }

        return $options;
    }

    /** Invalidate every cached list at once (called on any master write). */
    public function flush(): void
    {
        // Atomic: two concurrent A11 edits both bump the version (read-then-write could lose one).
        $this->cache->add(self::VERSION_KEY, 1);
        $this->cache->increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 1);
    }

    /**
     * @template TModel of MasterRecord
     *
     * @param  Closure(): Builder<TModel>  $query
     * @return list<MasterItem>
     */
    private function remember(string $list, Closure $query): array
    {
        return $this->cache->remember(
            "masters:v{$this->version()}:{$list}",
            self::TTL_SECONDS,
            fn (): array => $query()->active()->ordered()->get()
                ->map(fn (MasterRecord $record): MasterItem => MasterItem::fromModel($record))
                ->values()
                ->all(),
        );
    }
}
