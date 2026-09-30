<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FeatureFlagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored feature-flag state (A15). Read through App\Services\Settings\FeatureFlags (cached);
 * written only by the ToggleFeatureFlag Action (audited).
 *
 * @property string $key
 * @property bool $is_enabled
 * @property string|null $updated_by_id
 */
final class FeatureFlag extends Model
{
    /** @use HasFactory<FeatureFlagFactory> */
    use HasFactory;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
