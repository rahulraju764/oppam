<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProfileViewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * "X viewed Y on day D, N times" (M03, M15). Written by RecordProfileView only.
 *
 * @property string $id
 * @property string $viewer_profile_id
 * @property string $viewed_profile_id
 * @property \Carbon\CarbonImmutable $view_date
 * @property int $count
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
        ];
    }
}
