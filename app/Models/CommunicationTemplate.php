<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Message template for Email, SMS, or In-App communication (A14).
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string $channel email, sms, in_app
 * @property string|null $subject
 * @property string|null $dlt_template_id
 * @property string $body
 * @property array<string, string>|null $variables
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CommunicationTemplate extends Model
{
    use HasUlids;

    protected $table = 'communication_templates';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
