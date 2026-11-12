<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Enums\AdminExportStatus;
use App\Models\AdminExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * A09 (P9.1a), hourly: ready report exports past their 24 hours lose their file and become EXPIRED;
 * an export still "being prepared" after STUCK_HOURS (its job was lost) becomes FAILED.
 */
final class ExpireAdminExports implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const STUCK_HOURS = 2;

    public function handle(): void
    {
        AdminExport::query()->where('status', AdminExportStatus::Queued->value)->where('created_at', '<', now()->subHours(self::STUCK_HOURS))
            ->update(['status' => AdminExportStatus::Failed->value, 'updated_at' => now()]);

        AdminExport::query()->where('status', AdminExportStatus::Ready->value)->where('expires_at', '<', now())
            ->chunkById(200, function ($exports): void {
                foreach ($exports as $export) {
                    if ($export->path !== null) {
                        Storage::disk((string) config('oppam.media.private_disk'))->delete($export->path);
                    }
                    $export->forceFill(['status' => AdminExportStatus::Expired, 'path' => null])->save();
                }
            });
    }
}
