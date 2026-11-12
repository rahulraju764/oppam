<?php

declare(strict_types=1);

namespace App\Actions\Admin\Settings;

use App\Enums\SettingKey;
use App\Models\AdminUser;
use App\Support\Facades\Settings;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A15 site logo (P8.4, owner 2026-11-12: editable). PNG or WebP only (no SVG: it can carry script),
 * checked by content, ≤ 512 KB, 100–2000 px; never stored as uploaded — decoded and re-encoded as
 * WebP (transparency kept), so nothing but pixels reaches the public disk, under a random name.
 * The setting `site.logo_path` points at it (through UpdateSettings: settings.edit, reason, audit);
 * the previous upload is deleted once the change is committed. "Remove" goes back to the bundled logo.
 */
final class UploadSiteLogo
{
    public const MAX_KB = 512;

    public function __construct(private readonly UpdateSettings $settings) {}

    /** @throws ValidationException */
    public function handle(AdminUser $actor, UploadedFile $file, string $reason): string
    {
        Gate::forUser($actor)->authorize('settings.edit');

        Validator::make(['logo' => $file], [
            'logo' => ['required', 'file', 'mimes:png,webp', 'mimetypes:image/png,image/webp', 'max:'.self::MAX_KB,
                'dimensions:min_width=100,min_height=30,max_width=2000,max_height=2000'],
        ], [], ['logo' => __('logo')])->validate();

        $image = @imagecreatefromstring((string) file_get_contents((string) $file->getRealPath()));
        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['logo' => __('This file is not an image we can read. Please choose a PNG or WebP file.')]);
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagewebp($image, null, 90);
        $webp = (string) ob_get_clean();
        imagedestroy($image);

        $path = 'site/logo-'.Str::random(24).'.webp';
        Storage::disk('public')->put($path, $webp);

        return $this->point($actor, $path, $reason);
    }

    /** Back to the bundled logo. */
    public function remove(AdminUser $actor, string $reason): void
    {
        $this->point($actor, '', $reason);
    }

    private function point(AdminUser $actor, string $path, string $reason): string
    {
        $old = Settings::string(SettingKey::SiteLogoPath);

        try {
            $this->settings->handle($actor, [SettingKey::SiteLogoPath->value => $path], $reason);
        } catch (Throwable $e) {
            if ($path !== '') {
                Storage::disk('public')->delete($path);   // refused (no reason, no permission): leave nothing behind
            }
            throw $e;
        }

        if ($old !== '' && $old !== $path) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($old));
        }

        return $path;
    }
}
