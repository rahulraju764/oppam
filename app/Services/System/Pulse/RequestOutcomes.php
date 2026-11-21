<?php

declare(strict_types=1);

namespace App\Services\System\Pulse;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Concerns\Ignores;
use Laravel\Pulse\Recorders\Concerns\Sampling;
use Symfony\Component\HttpFoundation\Response;

/**
 * A12 "latency / error rate" (P9.2b1, owner 2026-11-21): a Pulse recorder that counts EVERY request
 * (Pulse's own SlowRequests sees only slow ones) by status class and by duration bucket — counts
 * only, in Pulse's buckets (no per-request entries), batched into Pulse's one ingest per request.
 * No URL, user or IP is recorded. RequestHealth reads the last hour back.
 */
final class RequestOutcomes
{
    use ConfiguresAfterResolving, Ignores, Sampling;

    /** Pulse type prefixes: the status class / bucket is part of the TYPE (bucket-only records keep no key). */
    public const STATUS_TYPE = 'oppam_request_status:';

    public const DURATION_TYPE = 'oppam_request_ms:';

    public const STATUS_CLASSES = ['1xx', '2xx', '3xx', '4xx', '5xx'];

    /** Pulse keeps a key per record; one constant key is enough here. */
    private const KEY = 'all';

    /** Upper bucket edges in ms; a request slower than the last edge goes to "over". */
    public const BUCKETS = [100, 250, 500, 1000, 2500, 5000];

    public const OVER = 'over';

    public function __construct(protected Pulse $pulse) {}

    public function register(callable $record, Application $app): void
    {
        $this->afterResolving(
            $app,
            Kernel::class,
            function (Kernel $kernel) use ($record): void {
                if ($kernel instanceof HttpKernel) {
                    $kernel->whenRequestLifecycleIsLongerThan(-1, $record);
                }
            },
        );
    }

    public function record(Carbon $startedAt, Request $request, Response $response): void
    {
        if (! $this->shouldSample() || $this->shouldIgnore('/'.ltrim($request->path(), '/'))) {
            return;
        }

        $this->pulse->record(type: self::STATUS_TYPE.self::statusClass($response->getStatusCode()), key: self::KEY, timestamp: $startedAt)
            ->onlyBuckets()->count();

        $this->pulse->record(type: self::DURATION_TYPE.self::bucketOf((int) $startedAt->diffInMilliseconds()), key: self::KEY, timestamp: $startedAt)
            ->onlyBuckets()->count();
    }

    public static function statusClass(int $status): string
    {
        return intdiv(max(100, min(599, $status)), 100).'xx';
    }

    /** @return list<string> every Pulse type this recorder writes */
    public static function statusTypes(): array
    {
        return array_map(fn (string $class): string => self::STATUS_TYPE.$class, self::STATUS_CLASSES);
    }

    /** @return list<string> */
    public static function durationTypes(): array
    {
        return [...array_map(fn (int $edge): string => self::DURATION_TYPE.$edge, self::BUCKETS), self::DURATION_TYPE.self::OVER];
    }

    public static function bucketOf(int $durationMs): string
    {
        foreach (self::BUCKETS as $edge) {
            if ($durationMs <= $edge) {
                return (string) $edge;
            }
        }

        return self::OVER;
    }
}
