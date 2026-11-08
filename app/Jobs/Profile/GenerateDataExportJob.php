<?php

declare(strict_types=1);

namespace App\Jobs\Profile;

use App\Domain\Account\MemberDataExport;
use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Models\User;
use App\Notifications\Account\DataExportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds one "download my data" zip (M14, P8.2c): data.json (MemberDataExport) + photos/, written
 * to the private disk at exports/{code}.zip, READY for 24 hours. Only a QUEUED export is built, so
 * a retry or a second worker changes nothing twice. The payload is just the export id — no token.
 * A failure marks it FAILED (the member may ask again at once) and removes any half-written file.
 */
final class GenerateDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const READY_HOURS = 24;

    /** A request still QUEUED after this long was lost (worker killed, job dropped): it counts as FAILED. */
    public const STALE_MINUTES = 60;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('media');
    }

    public function handle(MemberDataExport $contents): void
    {
        $export = DataExport::query()->find($this->exportId);
        if ($export === null || $export->status !== DataExportStatus::Queued) {
            return;
        }

        $user = User::query()->find($export->user_id);
        if ($user === null || ! $user->isActive()) {
            $this->markFailed($export);

            return;
        }

        $disk = Storage::disk(self::disk());
        $path = 'exports/'.$export->code.'.zip';
        $local = tempnam(sys_get_temp_dir(), 'oppam-export-');

        try {
            if ($local === false) {
                throw new RuntimeException('Could not create a temporary file for the data export.');
            }

            $zip = new ZipArchive;
            if ($zip->open($local, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the data export zip.');
            }

            $zip->addFromString('data.json', (string) json_encode($contents->build($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            foreach ($contents->photoFiles($user) as $photo) {
                $bytes = Storage::disk($photo->disk)->get($photo->path);
                if (is_string($bytes)) {
                    $zip->addFromString($photo->name, $bytes);
                }
            }

            $zip->close();

            $stream = fopen($local, 'rb');
            if ($stream === false || ! $disk->writeStream($path, $stream)) {
                throw new RuntimeException('Could not store the data export.');
            }
            if (is_resource($stream)) {
                fclose($stream);
            }

            $ready = DB::transaction(function () use ($export, $path, $local): bool {
                $locked = DataExport::query()->whereKey($export->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== DataExportStatus::Queued) {
                    return false;
                }

                $locked->forceFill([
                    'status' => DataExportStatus::Ready,
                    'file_path' => $path,
                    'file_size_bytes' => (int) filesize($local),
                    'completed_at' => now(),
                    'expires_at' => now()->addHours(self::READY_HOURS),
                ])->save();

                return true;
            });

            if (! $ready) {
                $disk->delete($path);

                return;
            }

            $user->notify(new DataExportReady);
        } catch (Throwable $e) {
            report($e);
            $disk->delete($path);
            $this->markFailed($export);
        } finally {
            if (is_string($local) && is_file($local)) {
                @unlink($local);
            }
        }
    }

    /** The worker died, timed out or gave up: the member must be able to ask again (never stuck QUEUED). */
    public function failed(?Throwable $e): void
    {
        $export = DataExport::query()->find($this->exportId);
        if ($export !== null) {
            $this->markFailed($export);
        }
    }

    public static function disk(): string
    {
        return (string) config('oppam.media.private_disk');
    }

    private function markFailed(DataExport $export): void
    {
        DataExport::query()->whereKey($export->id)->where('status', DataExportStatus::Queued->value)
            ->update(['status' => DataExportStatus::Failed->value, 'updated_at' => now()]);
    }
}
