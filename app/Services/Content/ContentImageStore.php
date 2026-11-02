<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Images for A08 content. An upload is decoded and written again as WebP, so location data and
 * anything hidden in the file never reach the public disk. Bundled assets (`images/…`, seeded
 * content) are served as they are and never deleted.
 */
final class ContentImageStore
{
    private const DIRECTORY = 'content';

    private const MAX_SOURCE_SIDE = 8000;

    private const MAX_WIDTH = 1920;

    /**
     * @return array{image_path: string, image_width: int, image_height: int}
     *
     * @throws ValidationException not a readable JPEG, PNG or WebP
     */
    public function store(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

        if ($size === false || ! in_array($size[2], $allowed, true) || $size[0] > self::MAX_SOURCE_SIDE || $size[1] > self::MAX_SOURCE_SIDE) {
            throw $this->unreadable();
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw $this->unreadable();
        }

        $image = imagesx($source) > self::MAX_WIDTH ? imagescale($source, self::MAX_WIDTH) : $source;
        if ($image === false) {
            throw $this->unreadable();
        }

        ob_start();
        imagewebp($image, null, 82);
        $bytes = (string) ob_get_clean();

        $path = self::DIRECTORY.'/'.Str::ulid()->toBase32().'.webp';
        Storage::disk('public')->put($path, $bytes);

        return ['image_path' => $path, 'image_width' => imagesx($image), 'image_height' => imagesy($image)];
    }

    public function url(string $path): string
    {
        return $this->isUpload($path) ? Storage::disk('public')->url($path) : asset($path);
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $this->isUpload($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function isUpload(string $path): bool
    {
        return str_starts_with($path, self::DIRECTORY.'/');
    }

    private function unreadable(): ValidationException
    {
        return ValidationException::withMessages(['image' => __('We couldn’t read this image. Please choose a JPG, PNG or WebP.')]);
    }
}
