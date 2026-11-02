<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentType;
use App\Enums\HomeBlockKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The text of one fixed page section (A08 "homepage blocks"). Seeded by ContentSeeder.
 *
 * @property HomeBlockKey $block_key
 * @property array<string, string> $content
 */
final class HomeBlock extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\HomeBlockFactory> */
    use HasFactory;

    public function contentType(): ContentType
    {
        return ContentType::HomeBlock;
    }

    public function title(): string
    {
        return $this->block_key->label();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + ['block_key' => HomeBlockKey::class, 'content' => 'array'];
    }
}
