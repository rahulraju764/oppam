<?php

declare(strict_types=1);

namespace App\Jobs\Matching;

use App\Actions\Matching\BuildDailyMatches;
use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Daily matches for up to GenerateDailyMatches::CHUNK members (F06) on the `matching` queue. Each
 * member is built on its own (BuildDailyMatches is idempotent per date), so a retry or one bad
 * profile never duplicates nor blocks the rest of the chunk.
 */
final class GenerateDailyMatchesChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /** @param  list<string>  $profileIds */
    public function __construct(public readonly array $profileIds, public readonly ?string $forDate = null)
    {
        $this->onQueue('matching');
    }

    public function handle(BuildDailyMatches $build): void
    {
        Profile::query()->whereIn('id', $this->profileIds)->with(['user', 'partnerPreference', 'district'])->get()
            ->each(function (Profile $profile) use ($build): void {
                try {
                    $build->handle($profile, $this->forDate);
                } catch (Throwable $e) {
                    report($e);   // one member's failure must not stop the chunk
                }
            });
    }
}
