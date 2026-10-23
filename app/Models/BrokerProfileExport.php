<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrokerExportType;
use Database\Factories\BrokerProfileExportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One export attempt (B.7) — allowed or denied, always logged (R-M13-13). Append-only (R-A07-7): no updated_at. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $broker_id
 * @property string $user_id
 * @property string $profile_id
 * @property BrokerExportType $export_type
 * @property bool $allowed
 * @property string|null $ip
 * @property Carbon $created_at
 * @property-read Broker|null $broker
 * @property-read Profile|null $profile
 */
final class BrokerProfileExport extends Model
{
    /** @use HasFactory<BrokerProfileExportFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'export_type' => BrokerExportType::class,
            'allowed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
