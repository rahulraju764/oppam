<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;
use App\Data\Settings\IntegrationStatusData;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** File storage (M11): the two disks member media really use — write, read back and delete a tiny probe file on each. */
final class StorageProbe implements IntegrationProbe
{
    public function key(): string
    {
        return 'storage';
    }

    public function label(): string
    {
        return __('File storage');
    }

    public function facts(): array
    {
        return [
            __('Public media disk') => (string) config('oppam.media.public_disk'),
            __('Private media disk') => (string) config('oppam.media.private_disk'),
        ];
    }

    public function check(AdminUser $admin): IntegrationStatusData
    {
        $failed = [];
        foreach (array_unique([(string) config('oppam.media.public_disk'), (string) config('oppam.media.private_disk')]) as $disk) {
            $path = 'health/probe-'.Str::random(16).'.txt';
            $content = 'probe '.now()->toIso8601String();
            try {
                Storage::disk($disk)->put($path, $content);
                $ok = Storage::disk($disk)->get($path) === $content;
                Storage::disk($disk)->delete($path);
            } catch (Throwable $e) {
                report($e);
                $ok = false;
            }
            if (! $ok) {
                $failed[] = $disk;
            }
        }

        return $failed === []
            ? IntegrationStatusData::ok(__('Write, read and delete worked on both media disks.'))
            : IntegrationStatusData::error(__('Storage failed on: :disks.', ['disks' => implode(', ', $failed)]));
    }
}
