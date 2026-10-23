<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrustedDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device a bureau Owner / Manager proved with an OTP (§8.1 "OTP challenge on each new device"). Keyed by the
 * SHA-256 of the random device cookie — never the cookie itself. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $user_id
 * @property string $device_hash
 * @property Carbon $verified_at
 * @property Carbon|null $last_seen_at
 * @property-read User|null $user
 */
final class TrustedDevice extends Model
{
    /** @use HasFactory<TrustedDeviceFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
