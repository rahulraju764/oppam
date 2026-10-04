<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProfileViewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "X viewed Y on day D, N times" (M03, M15). Written by RecordProfileView only.
 *
 * @property string $id
 * @property string $viewer_profile_id
 * @property string $viewed_profile_id
 * @property \Carbon\CarbonImmutable $view_date
 * @property int $count
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property-read Profile|null $viewer
 * @property-read Profile|null $viewed
 */
final class ProfileView extends Model
{
    /** @use HasFactory<ProfileViewFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'view_date' => 'immutable_date',
            'count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Profile, $this> */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'viewer_profile_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function viewed(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'viewed_profile_id');
    }
}
