<?php

declare(strict_types=1);

namespace App\Jobs\Matching;

use App\Actions\Matching\BuildDailyMatches;
use App\Domain\Matching\DailyBatch;
use App\Enums\ProfileStatus;
use App\Models\DailyMatch;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily matches fan-out (F06), scheduled at 05:00 IST: walks the ACTIVE members in id order (never
 * all ids in memory) and queues one GenerateDailyMatchesChunk per CHUNK members on the `matching`
 * queue. Also prunes batches older than BuildDailyMatches::REPEAT_DAYS (kept until then so a
 * profile isn't shown again within that window).
 */
final class GenerateDailyMatches implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHUNK = 1000;

    public const PRUNE_BATCH = 5000;

    public int $timeout = 300;

    public function __construct(public readonly ?string $forDate = null)
    {
        $this->onQueue('matching');
    }

    public function handle(): void
    {
        $date = $this->forDate ?? DailyBatch::today();

        // Batches the repeat window no longer needs, PRUNE_BATCH rows at a time (match_date index).
        $cutoff = CarbonImmutable::parse($date)->subDays(BuildDailyMatches::REPEAT_DAYS + 1)->toDateString();
        do {
            $deleted = DailyMatch::query()->where('match_date', '<', $cutoff)->limit(self::PRUNE_BATCH)->delete();
        } while ($deleted === self::PRUNE_BATCH);

        Profile::query()
            ->where('status', ProfileStatus::Active->value)
            ->whereNotNull('published_at')
            ->select('id')
            ->chunkById(self::CHUNK, function ($profiles) use ($date): void {
                GenerateDailyMatchesChunk::dispatch($profiles->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(), $date);
            });
    }
}
