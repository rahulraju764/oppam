<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored setting value (A15). Read through App\Services\Settings\SettingsRepository (typed,
 * cached); written only by the UpdateSetting Action (validated, audited).
 *
 * @property string $key
 * @property mixed $value
 * @property string|null $updated_by_id
 */
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
