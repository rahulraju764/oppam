<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginMethod;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One successful member/broker sign-in (M01). Written only by RecordLogin; never updated.
 *
 * @property string $id
 * @property string $user_id
 * @property LoginMethod $method
 * @property string $device_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 */
final class LoginEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => LoginMethod::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
