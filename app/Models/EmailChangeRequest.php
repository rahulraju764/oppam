<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailChangeRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "verify this email address" link (M14, P8.2b). Written only by RequestEmailChange, consumed
 * only by ConfirmEmailChange; the token itself is never stored (token_hash = SHA-256).
 *
 * @property string $id
 * @property string $user_id
 * @property string $email
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class EmailChangeRequest extends Model
{
    /** @use HasFactory<EmailChangeRequestFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
