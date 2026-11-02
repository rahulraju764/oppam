<?php

declare(strict_types=1);

namespace App\Queries\Content;

use App\Enums\ContentType;

/** Set by the signed preview route, for this request only: which row PublicContent shows as a draft. */
final class PreviewContext
{
    private ?ContentType $type = null;

    private ?string $code = null;

    private ?int $version = null;

    public function set(ContentType $type, string $code, int $version): void
    {
        $this->type = $type;
        $this->code = $code;
        $this->version = $version;
    }

    public function clear(): void
    {
        $this->type = null;
        $this->code = null;
        $this->version = null;
    }

    public function version(): ?int
    {
        return $this->version;
    }

    public function active(): bool
    {
        return $this->type !== null;
    }

    public function codeFor(ContentType $type): ?string
    {
        return $this->type === $type ? $this->code : null;
    }
}
