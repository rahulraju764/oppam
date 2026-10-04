<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesBenchDatabase;
use App\Data\Search\SearchCriteria;
use App\Enums\ProfileStatus;
use App\Enums\SearchSort;
use App\Models\Masters\Caste;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\MasterOption;
use App\Models\Profile;
use App\Services\Masters\Masters;
use App\Services\Profile\ProfileSearch;
use Illuminate\Console\Command;

/**
 * P2.1 (M04 acceptance "< 800 ms p95 at 100k profiles"): run N searches against the bench database
 * as random members, each with a random mix of filters and sort — the count query plus the first
 * page, as the search page does — and report p50 / p95 / max. Seed it first with
 * profiles:seed-bulk. Fails (exit 1) when p95 is over the budget.
 */
final class BenchmarkSearch extends Command
{
    use UsesBenchDatabase;

    protected $signature = 'profiles:search-benchmark {--runs=200 : searches to time} {--budget=800 : p95 budget in ms}';

    protected $description = 'Time member searches on the bench database (local only)';

    public function handle(): int
    {
        if (! $this->benchAllowed()) {
            return self::FAILURE;
        }

        $this->benchDatabase();
        $this->useBench();
        app(Masters::class)->flush();

        $total = Profile::query()->where('status', ProfileStatus::Active->value)->count();
        if ($total < 1000) {
            $this->error('The bench database has '.$total.' active profiles. Run profiles:seed-bulk first.');

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $search = app(ProfileSearch::class);
        $searchers = Profile::query()->where('status', ProfileStatus::Active->value)->inRandomOrder()->limit($runs)->get();
        $pools = $this->pools();
        $times = [];
        $bar = $this->output->createProgressBar($searchers->count());

        $runsLog = [];
        foreach ($searchers as $searcher) {
            $filters = $this->randomFilters($pools);
            $criteria = SearchCriteria::fromInput($filters);
            $started = hrtime(true);
            $search->count($searcher, $criteria);
            $counted = hrtime(true);
            $search->page($searcher, $criteria);
            $ms = (hrtime(true) - $started) / 1_000_000;
            $times[] = $ms;
            $runsLog[] = [$ms, ($counted - $started) / 1_000_000, $filters];
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        sort($times);

        // The slowest searches and their filters — where to look when p95 regresses.
        usort($runsLog, fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $this->table(['total ms', 'count ms', 'filters'], array_map(fn (array $r): array => [
            round($r[0]), round($r[1]), (string) json_encode($r[2]),
        ], array_slice($runsLog, 0, 5)));
        $p = fn (float $q): float => $times[(int) min(count($times) - 1, floor($q * count($times)))];
        $p95 = $p(0.95);

        $this->table(['active profiles', 'searches', 'p50 ms', 'p95 ms', 'max ms'], [[
            number_format($total), count($times), round($p(0.5)), round($p95), round((float) end($times)),
        ]]);

        $budget = (int) $this->option('budget');
        if ($p95 > $budget) {
            $this->error("p95 is over the {$budget} ms budget.");

            return self::FAILURE;
        }

        $this->info("p95 is within the {$budget} ms budget.");

        return self::SUCCESS;
    }

    /** @return array<string, list<int>> */
    private function pools(): array
    {
        return [
            'castes' => Caste::query()->pluck('id')->all(),
            'districts' => District::query()->pluck('id')->all(),
            'education' => Education::query()->pluck('id')->all(),
            'diet' => MasterOption::query()->where('group', 'diet')->pluck('id')->all(),
        ];
    }

    /**
     * 0–4 random filters plus a random sort, the way members actually combine them.
     *
     * @param  array<string, list<int>>  $pools
     * @return array<string, mixed>
     */
    private function randomFilters(array $pools): array
    {
        $candidates = [
            fn (): array => ['age_min' => $min = mt_rand(21, 30), 'age_max' => $min + mt_rand(2, 8)],
            fn (): array => ['caste' => [$pools['castes'][array_rand($pools['castes'])]]],
            fn (): array => ['district' => array_values(array_intersect_key($pools['districts'], array_flip((array) array_rand($pools['districts'], min(3, count($pools['districts']))))))],
            fn (): array => ['marital' => ['NEVER_MARRIED']],
            fn (): array => ['verified' => '1'],
            fn (): array => ['height_min' => mt_rand(150, 170)],
            fn (): array => ['education_min' => $pools['education'][array_rand($pools['education'])]],
            fn (): array => $pools['diet'] !== [] ? ['diet' => $pools['diet'][array_rand($pools['diet'])]] : [],
            fn (): array => ['active' => '30d'],
            fn (): array => ['nri' => '1'],
        ];
        shuffle($candidates);

        $filters = [];
        foreach (array_slice($candidates, 0, mt_rand(0, 4)) as $make) {
            $filters = [...$filters, ...$make()];
        }

        $sorts = SearchSort::cases();
        $filters['sort'] = $sorts[array_rand($sorts)]->value;

        return $filters;
    }
}
