<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * An office on /branches (M12, A08).
 *
 * @property string $city
 * @property string $role
 * @property string $address
 * @property string $phone
 * @property string $tel
 * @property string $hours
 * @property string|null $map_url
 */
final class Branch extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\BranchFactory> */
    use HasFactory;

    public function contentType(): ContentType
    {
        return ContentType::Branch;
    }

    public function title(): string
    {
        return $this->city;
    }
}
