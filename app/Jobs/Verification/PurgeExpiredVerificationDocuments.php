<?php

declare(strict_types=1);

namespace App\Jobs\Verification;

use App\Actions\Verification\PurgeVerificationDocuments;
use App\Enums\SettingKey;
use App\Enums\VerificationStatus;
use App\Models\Profile;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Daily (routes/console.php): delete the ID files of every request decided longer ago than
 * `verification.purge_after_days` (90; M09) and verify each deletion (PurgeVerificationDocuments).
 * Idempotent and chunked; one system audit row per run with the counts (A05 "daily purge job
 * verified"). A request whose files could not be confirmed deleted is retried on the next run.
 */
final class PurgeExpiredVerificationDocuments implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PurgeVerificationDocuments $purge, AuditLogger $audit): void
    {
        $purged = 0;
        $failed = 0;

        VerificationRequest::query()
            ->whereNull('documents_purged_at')
            ->whereNotIn('status', VerificationStatus::open())
            ->where('decided_at', '<=', now()->subDays(Settings::int(SettingKey::VerificationPurgeAfterDays)))
            // A10 legal hold (P6.2, decisions 2026-10-23): a held member's documents wait for the hold to lift.
            ->whereNotIn('profile_id', Profile::withTrashed()->whereNotNull('legal_hold_at')->select('id'))
            ->lazyById(100)
            ->each(function (VerificationRequest $request) use ($purge, &$purged, &$failed): void {
                try {
                    $purge->handle($request) ? $purged++ : $failed++;
                } catch (Throwable $e) {
                    report($e);   // retried tomorrow; the rest still run
                    $failed++;
                }
            });

        if ($purged + $failed > 0) {
            $audit->record('verification.documents_purged', after: ['requests' => $purged, 'not_confirmed' => $failed]);
        }
    }
}
