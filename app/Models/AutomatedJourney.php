<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Automated customer journey rule (A14).
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int $trigger_days
 * @property string $channel
 * @property string|null $template_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CommunicationTemplate|null $template
 */
final class AutomatedJourney extends Model
{
    use HasUlids;

    protected $table = 'automated_journeys';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trigger_days' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CommunicationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'template_id');
    }
}
