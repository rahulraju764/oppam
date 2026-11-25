<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Eloquent strictness (P9.3c N+1 hunt). Outside production a lazy load, a silently discarded attribute and
 * a missing attribute THROW (tests and local fail fast). In production a lazy load is still detected but only
 * LOGGED (model + relation, never ids or data) so an N+1 the tests missed shows up in the logs instead of
 * slowing pages silently — Laravel calls the violation handler only while prevention is on, so production
 * keeps prevention on with a non-throwing handler.
 */
final class LazyLoadingGuard
{
    /** @var array<string, true> model::relation already reported by this process */
    private static array $reported = [];

    public static function configure(bool $production): void
    {
        self::$reported = [];
        Model::shouldBeStrict(! $production);

        if ($production) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
                // One line per model + relation per process (an N+1 over 20 rows would otherwise log 20) — review P9.3c.
                $key = $model::class.'::'.$relation;
                if (isset(self::$reported[$key])) {
                    return;
                }
                self::$reported[$key] = true;
                Log::warning('Lazy loading detected (N+1)', ['model' => $model::class, 'relation' => $relation]);
            });
        } else {
            Model::handleLazyLoadingViolationUsing(null);   // the default: throw LazyLoadingViolationException
        }
    }
}
