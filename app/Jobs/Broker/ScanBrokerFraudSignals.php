<?php

declare(strict_types=1);

namespace App\Jobs\Broker;

use App\Actions\Admin\Brokers\Signals\RecordFraudSignals;
use App\Domain\Broker\Import\ImportBatchSignals;
use App\Enums\BrokerStatus;
use App\Enums\ImportBatchStatus;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Daily A07 fraud & quality scan (§11A B.12; scheduled 04:15 IST): first the batch-level flags of
 * recent import batches (ImportBatchSignals — shared mobile patterns, matching photos), then every
 * bureau that still works or is under review (APPLIED / KYC_PENDING / ACTIVE / SUSPENDED) through
 * RecordFraudSignals. One bureau failing never stops the others (reported, retried tomorrow).
 */
final class ScanBrokerFraudSignals implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;   // below the queue's retry_after (960 s), so a long run is never handed out twice

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ImportBatchSignals $batches, RecordFraudSignals $signals): void
    {
        BrokerImportBatch::query()
            ->whereIn('status', [ImportBatchStatus::Ready, ImportBatchStatus::Importing, ImportBatchStatus::Completed])
            ->where('created_at', '>=', now()->subDays(30))
            ->lazyById(50)
            ->each(function (BrokerImportBatch $batch) use ($batches): void {
                try {
                    $batches->analyse($batch);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        Broker::query()
            ->whereIn('status', [BrokerStatus::Applied, BrokerStatus::KycPending, BrokerStatus::Active, BrokerStatus::Suspended])
            ->lazyById(50)
            ->each(function (Broker $broker) use ($signals): void {
                try {
                    $signals->handle($broker);
                } catch (Throwable $e) {
                    report($e);
                }
            });
    }
}
